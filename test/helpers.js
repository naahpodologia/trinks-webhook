'use strict';
// Gera mensagens SNS assinadas com uma chave de teste, como a AWS faria.
const crypto = require('node:crypto');
const { buildStringToSign } = require('../src/sns');

const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
const PUBLIC_PEM = publicKey.export({ type: 'spki', format: 'pem' });
const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-teste.pem';
const TOPIC = 'arn:aws:sns:us-east-1:123456789012:trinks-webhook-teste';

function sign(msg, version = '1') {
  const m = { ...msg, SignatureVersion: version, SigningCertURL: CERT_URL };
  const s = crypto.createSign(version === '2' ? 'RSA-SHA256' : 'RSA-SHA1');
  s.update(buildStringToSign(m));
  m.Signature = s.sign(privateKey, 'base64');
  return m;
}

function notification(payload, extra = {}) {
  return sign({
    Type: 'Notification',
    MessageId: crypto.randomUUID(),
    TopicArn: TOPIC,
    Subject: 'Trinks',
    Message: JSON.stringify(payload),
    Timestamp: new Date().toISOString(),
    ...extra,
  });
}

function subscriptionConfirmation() {
  return sign({
    Type: 'SubscriptionConfirmation',
    MessageId: crypto.randomUUID(),
    Token: 'token-de-teste',
    TopicArn: TOPIC,
    Message: 'You have chosen to subscribe to the topic ...',
    SubscribeURL: `https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=${TOPIC}&Token=token-de-teste`,
    Timestamp: new Date().toISOString(),
  });
}

const fetchCert = async (url) => { if (url !== CERT_URL) throw new Error('url inesperada'); return PUBLIC_PEM; };

module.exports = { sign, notification, subscriptionConfirmation, fetchCert, TOPIC, CERT_URL };
