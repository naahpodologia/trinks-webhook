# Receptor do webhook do Trinks (PHP)

Recebe os avisos que o Trinks manda (via Amazon SNS) quando algo acontece na Naah Podologia: conta fechada, cliente novo, agendamento, estoque etc. Cada aviso é conferido, organizado e gravado em arquivo.

Roda em qualquer hospedagem com PHP 8.0 ou mais novo, com as extensões `openssl`, `curl` e `json` (padrão na Hostinger). Não há dependências externas nem banco de dados.

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

Para mandar os dados para outro lugar (planilha, banco, CRM), plugue uma função `aoReceber` no `Receptor` (veja `lib/Receptor.php`).

## Endereços

- Webhook: `POST https://<subdominio>/webhook?token=<token>`
- Saúde: `GET https://<subdominio>/health` responde `{"ok":true}`

## Configuração

Copie `config.exemplo.php` para **`/home/<usuario>/trinks-webhook/config.php`**, fora da pasta pública, e preencha o `token`. Os dados vão por padrão para `/home/<usuario>/trinks-webhook/dados`, também fora da pasta pública. Sem arquivo de configuração o receptor funciona, só não exige o token.

## Testar

```bash
php tests/rodar.php     # 20 testes, inclusive um servidor HTTP de verdade
```

O `.htaccess` bloqueia o acesso pela web a `lib/`, `tests/`, configuração e arquivos de dados.

## Limitações conhecidas

- A página do evento **Inclusão de Agendamento** não abriu durante a leitura da doc. O exemplo `tests/payloads/11-...HIPOTETICO.json` é inventado. Os eventos 2 e 4 a 13 são gravados por inteiro, mas o resumo deles ainda é genérico. Ajustamos quando chegar o primeiro evento real.
- A doc do Trinks não mostra `TopicArn` nem `SignatureVersion` no exemplo do envelope. O receptor segue o padrão da AWS, que inclui esses campos. Se as mensagens reais vierem diferentes, a checagem de assinatura vai acusar e ajustamos.
- A primeira versão foi escrita em Node.js (está no histórico do Git). Ela foi trocada por PHP porque o plano Starter da Hostinger não roda Node.js.
