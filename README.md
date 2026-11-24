# Receptor do webhook do Trinks

Recebe os avisos que o Trinks manda (via Amazon SNS) quando algo acontece na Naah Podologia: conta fechada, cliente novo, agendamento, estoque etc. Cada aviso é conferido, organizado e gravado em arquivo.

Não precisa instalar nada além do Node.js 18 ou mais novo. Não há dependências externas.

## O que ele faz

1. **Confirma a inscrição.** Quando a URL é cadastrada, o SNS manda uma mensagem `SubscriptionConfirmation`. O receptor abre a `SubscribeURL` sozinho e anota o resultado em `data/inscricoes.jsonl`.
2. **Confere a assinatura.** Toda mensagem é verificada com o certificado da AWS (`SigningCertURL`), aceitando só certificados de `sns.<região>.amazonaws.com`. Mensagem adulterada ou de fora recebe 403.
3. **Lê o evento.** Abre o campo `Message`, identifica o tipo (1 a 14) e a ação (1 inclusão, 2 alteração, 3 exclusão, 9 estorno), converte valores como `"13,50"` em número e monta um resumo.
4. **Grava.**
   - `data/sns/AAAA-MM-DD.jsonl`: toda mensagem recebida, crua (auditoria).
   - `data/eventos/AAAA-MM-DD/<evento>.jsonl`: eventos organizados, com `resumo` e `payload` completo.
   - `data/vistos.txt`: ids já processados, para ignorar reenvios do SNS.
5. **Responde certo ao SNS.** 200 quando deu certo (ou é duplicado), 500 quando falhou algo temporário (o SNS tenta de novo), 403 quando a assinatura é inválida.

Para mandar os dados para outro lugar (Google Sheets, banco, CRM), basta plugar uma função `onEvent` em `src/server.js`. Ela recebe o evento já organizado.

## Rodar no computador

```bash
npm test                         # 21 testes com mensagens assinadas de exemplo
ALLOW_UNSIGNED=true npm start    # sobe em http://localhost:3000/webhook/trinks
npm run simular                  # em outro terminal: envia os 4 exemplos
```

`ALLOW_UNSIGNED=true` desliga a checagem de assinatura e serve **só para teste local**.

## Configuração (variáveis de ambiente)

Veja `.env.example`. As principais:

| Variável | Para quê |
|---|---|
| `PORT` | Porta (a Hostinger define sozinha) |
| `WEBHOOK_PATH` | Caminho do endpoint. Sugestão: algo difícil de adivinhar |
| `WEBHOOK_TOKEN` | Se preenchido, exige `?token=...` na URL cadastrada no Trinks |
| `DATA_DIR` | Onde gravar. Na Hostinger, use uma pasta **fora** da pasta do app, porque um novo deploy apaga o conteúdo |
| `ALLOWED_TOPIC_ARNS` | Tópico(s) SNS do Trinks, quando eles informarem |
| `AUTO_CONFIRM_SUBSCRIPTION` | `false` se preferir confirmar a inscrição na mão |

## Publicar na Hostinger (quando aprovado)

App Node.js com comando de início `npm start`. A URL final fica, por exemplo,
`https://webhook.naah.com.br/webhook/trinks?token=XXXX`. Precisa ser HTTPS com certificado válido e ficar no ar 24h.
Depois de publicar, teste `https://.../health` (deve responder `{"ok":true}`) e só então mande a URL ao Trinks.

## Limitações conhecidas

- A página do evento **Inclusão de Agendamento** não abriu durante a leitura da doc. O exemplo `11-...HIPOTETICO.json` é inventado. Os eventos 2 e 4 a 13 são gravados por inteiro, mas o resumo deles ainda é genérico. Ajustamos quando chegar o primeiro evento real.
- A doc do Trinks não mostra `TopicArn` nem `SignatureVersion` no exemplo do envelope. O receptor segue o padrão da AWS, que inclui esses campos. Se as mensagens reais vierem diferentes, a checagem de assinatura vai acusar e ajustamos.
- Gravação em arquivo é simples de propósito. Para volume alto ou relatórios, o próximo passo é um banco (MySQL da Hostinger).
