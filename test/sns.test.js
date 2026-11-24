'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { verifySnsMessage, isAwsSnsUrl, confirmSubscription } = require('../src/sns');
const h = require('./helpers');

test('aceita assinatura válida (SignatureVersion 1 e 2)', async () => {
  for (const v of ['1', '2']) {
    const m = h.sign({ Type: 'Notification', MessageId: 'x', TopicArn: h.TOPIC, Message: '{}', Timestamp: 't' }, v);
    assert.deepStrictEqual(await verifySnsMessage(m, { fetchCert: h.fetchCert }), { ok: true });
  }
});

test('aceita mensagem sem Subject', async () => {
  const m = h.sign({ Type: 'Notification', MessageId: 'x', TopicArn: h.TOPIC, Message: '{}', Timestamp: 't' });
  assert.ok((await verifySnsMessage(m, { fetchCert: h.fetchCert })).ok);
});

test('rejeita mensagem adulterada', async () => {
  const m = h.notification({ TipoDeEvento: 1, Action: 1, ValorDaCompra: '10' });
  m.Message = m.Message.replace('"10"', '"99999"');
  const r = await verifySnsMessage(m, { fetchCert: h.fetchCert });
  assert.strictEqual(r.ok, false);
  assert.strictEqual(r.reason, 'assinatura inválida');
});

test('rejeita certificado fora da AWS', async () => {
  const m = h.notification({});
  m.SigningCertURL = 'https://sns.us-east-1.amazonaws.com.atacante.com/cert.pem';
  assert.strictEqual((await verifySnsMessage(m, { fetchCert: h.fetchCert })).ok, false);
});

test('valida URLs da AWS SNS', () => {
  assert.ok(isAwsSnsUrl('https://sns.sa-east-1.amazonaws.com/x.pem', { requirePem: true }));
  assert.ok(!isAwsSnsUrl('http://sns.sa-east-1.amazonaws.com/x.pem'));
  assert.ok(!isAwsSnsUrl('https://evil.com/sns.us-east-1.amazonaws.com'));
  assert.ok(!isAwsSnsUrl('https://sns.sa-east-1.amazonaws.com/x.txt', { requirePem: true }));
});

test('confirmação de inscrição chama a SubscribeURL e lê o ARN', async () => {
  let chamada;
  const r = await confirmSubscription(h.subscriptionConfirmation(), {
    get: async (url) => { chamada = url; return '<ConfirmSubscriptionResult><SubscriptionArn>arn:aws:sns:us-east-1:1:t:abc</SubscriptionArn></ConfirmSubscriptionResult>'; },
  });
  assert.match(chamada, /^https:\/\/sns\.us-east-1\.amazonaws\.com\/\?Action=ConfirmSubscription/);
  assert.strictEqual(r.subscriptionArn, 'arn:aws:sns:us-east-1:1:t:abc');
});

test('confirmação recusa SubscribeURL fora da AWS', async () => {
  const m = h.subscriptionConfirmation();
  m.SubscribeURL = 'https://atacante.com/confirm';
  await assert.rejects(confirmSubscription(m, { get: async () => '' }));
});
