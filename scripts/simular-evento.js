'use strict';
// Envia os payloads de exemplo para um receptor rodando localmente.
// Uso: ALLOW_UNSIGNED=true npm start   (em outro terminal)
//      npm run simular [-- http://localhost:3000/webhook/trinks]
// As mensagens vão SEM assinatura válida, por isso o receptor precisa estar com ALLOW_UNSIGNED=true.

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const alvo = process.argv[2] || 'http://localhost:3000/webhook/trinks';
const dir = path.join(__dirname, '..', 'test', 'payloads');

(async () => {
  for (const f of fs.readdirSync(dir).sort()) {
    const payload = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
    const msg = {
      Type: 'Notification', MessageId: crypto.randomUUID(), TopicArn: 'arn:aws:sns:local:0:simulacao',
      Subject: 'Simulação', Message: JSON.stringify(payload), Timestamp: new Date().toISOString(),
      SignatureVersion: '1', Signature: 'simulado', SigningCertURL: 'https://sns.us-east-1.amazonaws.com/simulado.pem',
    };
    const res = await fetch(alvo, { method: 'POST', headers: { 'content-type': 'text/plain', 'x-amz-sns-message-type': 'Notification' }, body: JSON.stringify(msg) });
    console.log(`${f}: HTTP ${res.status} ${await res.text()}`);
  }
})().catch((e) => { console.error('Falhou:', e.message); process.exit(1); });
