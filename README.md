# Receptor do webhook do Trinks (PHP)

Recebe os avisos que o Trinks manda (via Amazon SNS) quando algo acontece na Naah Podologia: conta fechada, cliente novo, agendamento, estoque etc. Cada aviso é conferido, organizado e gravado em arquivo e, se configurado, num banco MySQL.

Roda em qualquer hospedagem com PHP 8.0 ou mais novo, com as extensões `openssl`, `curl` e `json` (padrão na Hostinger). Não há dependências externas. O banco MySQL é opcional (extensão `pdo_mysql`, também padrão na Hostinger).

## O que ele faz

1. **Confirma a inscrição.** Quando a URL é cadastrada, o SNS manda uma mensagem `SubscriptionConfirmation`. O receptor abre a `SubscribeURL` sozinho e anota o resultado em `dados/inscricoes.jsonl`.
2. **Confere a assinatura.** Toda mensagem é verificada com o certificado da AWS (`SigningCertURL`), aceitando só certificados de `sns.<região>.amazonaws.com`. Mensagem adulterada ou de fora recebe 403.
3. **Lê o evento.** Abre o campo `Message`, identifica o tipo (1 a 14) e a ação (1 inclusão, 2 alteração, 3 exclusão, 9 estorno), converte valores como `"13,50"` em número e monta um resumo.
4. **Grava** na pasta de dados:
   - `sns/AAAA-MM-DD.jsonl`: toda mensagem recebida, crua (auditoria)
   - `eventos/AAAA-MM-DD/<evento>.jsonl`: eventos organizados, com `resumo` e `payload` completo
   - `vistos.txt`: ids já processados, para ignorar reenvios do SNS
   - `log/AAAA-MM-DD.jsonl`: o que aconteceu, inclusive recusas
5. **Responde certo ao SNS.** 200 quando deu certo (ou é duplicado), 500 quando falhou algo temporário (o SNS tenta de novo), 403 quando a assinatura é inválida.

6. **Grava no banco MySQL** (se houver o bloco `banco` no `config.php`), depois do repasse. Veja abaixo.

Para mandar os dados para outro lugar (planilha, CRM), plugue uma função `aoReceber` no `Receptor` (veja `lib/Receptor.php`).

## Banco MySQL

Com o bloco `banco` no `config.php`, cada evento também vai para o MySQL, em tabelas prontas para relatório. As tabelas são criadas sozinhas na primeira vez.

```php
'banco' => [
    'host' => 'localhost',
    'nome' => 'u000000000_trinks',
    'usuario' => 'u000000000_trinks',
    'senha' => 'senha-do-banco',
],
```

| Tabela | Uma linha por | Principais colunas |
|---|---|---|
| `trinks_eventos` | evento recebido | `recebido_em`, `evento`, `acao`, `payload` (JSON original completo) |
| `trinks_vendas` | conta fechada | `data_fechamento`, `cliente_nome`, `valor_compra`, `total_servicos`, `total_produtos`, `total_descontos`, `total_recebido`, `estornada` |
| `trinks_venda_itens` | serviço ou produto vendido | `id_transacao`, `tipo`, `nome`, `quantidade`, `valor_unitario`, `desconto`, `total` |
| `trinks_venda_pagamentos` | forma de pagamento da venda | `id_transacao`, `forma_pagamento`, `valor` |
| `trinks_clientes` | cliente (dados mais recentes) | `nome`, `telefone`, `email`, `data_nascimento`, `cidade`, `como_nos_conheceu`, `origem`, `data_cadastro` |
| `trinks_agendamentos` | agendamento (status mais recente) | `inicio`, `cliente_nome`, `servico`, `status`, `excluido` |
| `trinks_profissionais` | profissional | `nome`, `ativo` |
| `trinks_estoque_movimentacoes` | entrada/saída de produto | `data_movimentacao`, `produto`, `tipo`, `quantidade` (negativa = saída), `custo_total`, `estoque_apos` |

Regras:

- **Não atrapalha o Trinks nem o Retorne.** O banco só é usado depois do repasse, e uma falha no banco nunca muda a resposta. Se o banco estiver fora do ar, o evento vai para `dados/banco-pendentes.jsonl` e é gravado sozinho quando o banco voltar.
- **Não duplica.** Reenvios do SNS e importações repetidas atualizam a mesma linha. Um campo que vier vazio numa alteração não apaga o valor que já estava gravado.
- Datas ficam em horário de Brasília. Valores em reais ficam como número (`DECIMAL`), já convertidos de `"1.234,56"`.
- Os arquivos em `dados/` continuam sendo gravados como antes: o banco é uma cópia organizada, não a única.

Endereços do banco (com o mesmo token do webhook):

- `GET /banco?token=<token>`: diz se conectou e quantos registros há em cada tabela.
- `GET /banco/importar?token=<token>`: copia para o banco os eventos já guardados em arquivo (por exemplo, os recebidos antes de o banco existir). Pode ser aberto mais de uma vez sem duplicar.

### Consultas de exemplo

```sql
-- Faturamento por mês (sem estornos)
SELECT DATE_FORMAT(data_fechamento, '%Y-%m') AS mes, COUNT(*) AS vendas, SUM(total_recebido) AS recebido
FROM trinks_vendas WHERE estornada = 0 GROUP BY mes ORDER BY mes;

-- Serviços e produtos mais vendidos
SELECT i.tipo, i.nome, SUM(i.quantidade) AS qtd, SUM(i.total) AS total
FROM trinks_venda_itens i JOIN trinks_vendas v USING (id_transacao)
WHERE v.estornada = 0 GROUP BY i.tipo, i.nome ORDER BY total DESC;

-- Clientes novos por origem
SELECT COALESCE(como_nos_conheceu, origem, '(sem informação)') AS origem, COUNT(*) AS clientes
FROM trinks_clientes GROUP BY 1 ORDER BY clientes DESC;
```

### Ler no Python (no computador)

O banco fica no servidor da Hostinger. Para ler de fora, libere o IP do computador em hPanel > Bancos de dados > MySQL remoto e use o endereço `srvNNN.hstgr.io` que aparece lá (o `localhost` só vale dentro do servidor).

```python
# pip install pymysql pandas
import pymysql, pandas as pd

con = pymysql.connect(host="srvNNN.hstgr.io", user="u000000000_trinks",
                      password="...", database="u000000000_trinks")
vendas = pd.read_sql("SELECT * FROM trinks_vendas WHERE estornada = 0", con)
print(vendas.groupby(vendas.data_fechamento.dt.to_period("M")).total_recebido.sum())
```

## Repasse para outro sistema (ex.: Retorne)

O Trinks só aceita uma URL de webhook por franquia. Se outro sistema já usa essa URL, o receptor pode ficar no meio: grava a mensagem e a repassa, **sem alterar nenhum byte**, para o outro sistema. A assinatura da AWS continua válida no destino, porque ela cobre o conteúdo da mensagem e não o endereço.

Para ligar, coloque no `config.php`:

```php
'repassarPara' => ['https://url-do-retorne/aqui'],
```

- Só mensagens com assinatura válida são repassadas. Pedidos de confirmação de inscrição não são repassados.
- Só a resposta 200 conta como entregue. Qualquer outro status, erro de rede ou timeout (10 s), o receptor responde 500 ao SNS, que reenvia mais tarde. Do nosso lado o evento não é gravado duas vezes, e cada destino recebe cada mensagem uma vez só (`repassados.txt`).
- Cada repasse fica registrado no log do dia.

## Endereços

- Webhook: `POST https://<subdominio>/webhook?token=<token>`
- Saúde: `GET https://<subdominio>/health` responde `{"ok":true}`
- Banco: `GET https://<subdominio>/banco?token=<token>` e `/banco/importar?token=<token>` (veja acima)

## Configuração

Copie `config.exemplo.php` para **`/home/<usuario>/trinks-webhook/config.php`**, fora da pasta pública, e preencha o `token`. Os dados vão por padrão para `/home/<usuario>/trinks-webhook/dados`, também fora da pasta pública. Sem arquivo de configuração o receptor funciona, só não exige o token.

## Testar

```bash
php tests/rodar.php     # 29 testes, inclusive um servidor HTTP de verdade

# Com um MySQL de teste, roda também os 5 testes do banco:
TRINKS_TESTE_MYSQL="localhost;banco;usuario;senha" php tests/rodar.php
```

O `.htaccess` bloqueia o acesso pela web a `lib/`, `tests/`, configuração e arquivos de dados.

## Limitações conhecidas

- A página do evento **Inclusão de Agendamento** não abriu durante a leitura da doc. O exemplo `tests/payloads/11-...HIPOTETICO.json` é inventado. Os eventos 2 e 4 a 13 são gravados por inteiro (arquivo e `trinks_eventos.payload`), mas o resumo deles e as colunas de `trinks_agendamentos` e `trinks_profissionais` usam nomes de campo prováveis. Ajustamos quando chegar o primeiro evento real.
- A doc do Trinks não mostra `TopicArn` nem `SignatureVersion` no exemplo do envelope. O receptor segue o padrão da AWS, que inclui esses campos. Se as mensagens reais vierem diferentes, a checagem de assinatura vai acusar e ajustamos.
- A primeira versão foi escrita em Node.js (está no histórico do Git). Ela foi trocada por PHP porque o plano Starter da Hostinger não roda Node.js.
