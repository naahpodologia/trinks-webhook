'use strict';
// Validação de mensagens do Amazon SNS (assinatura, certificado, confirmação de inscrição).
// Referência: https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html

const crypto = require('node:crypto');
const https = require('node:https');

// Host oficial do SNS: sns.<regiao>.amazonaws.com (ou .com.cn)
const SNS_HOST_RE = /^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/;

const FIELDS = {
  Notification: ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
  SubscriptionConfirmation: ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
  UnsubscribeConfirmation: ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
};

function isAwsSnsUrl(value, { requirePem = false } = {}) {
  let url;
  try { url = new URL(value); } catch { return false; }
  if (url.protocol !== 'https:') return false;
  if (!SNS_HOST_RE.test(url.hostname)) return false;
  if (requirePem && !url.pathname.endsWith('.pem')) return false;
  return true;
}

// Monta o texto canônico que a AWS assina: "Campo\nValor\n" para cada campo presente.
function buildStringToSign(msg) {
  const fields = FIELDS[msg.Type];
  if (!fields) throw new Error(`Tipo de mensagem SNS desconhecido: ${msg.Type}`);
  let out = '';
  for (const f of fields) {
    if (msg[f] === undefined || msg[f] === null) continue; // Subject é opcional
    out += `${f}\n${msg[f]}\n`;
  }
  return out;
}

function httpsGet(url, timeoutMs = 5000) {
  return new Promise((resolve, reject) => {
    const req = https.get(url, { timeout: timeoutMs }, (res) => {
      if (res.statusCode < 200 || res.statusCode >= 300) {
        res.resume();
        return reject(new Error(`HTTP ${res.statusCode} ao acessar ${new URL(url).hostname}`));
      }
      let body = '';
      res.setEncoding('utf8');
      res.on('data', (c) => { body += c; if (body.length > 1e6) req.destroy(new Error('Resposta muito grande')); });
      res.on('end', () => resolve(body));
    });
    req.on('timeout', () => req.destroy(new Error('Tempo esgotado')));
    req.on('error', reject);
  });
}

const certCache = new Map();
async function defaultFetchCert(url) {
  if (certCache.has(url)) return certCache.get(url);
  const pem = await httpsGet(url);
  if (!pem.includes('-----BEGIN')) throw new Error('Certificado inválido');
  certCache.set(url, pem);
  return pem;
}

/**
 * Verifica a assinatura de uma mensagem SNS.
 * @returns {Promise<{ok: boolean, reason?: string}>}
 */
async function verifySnsMessage(msg, { fetchCert = defaultFetchCert, skipCertUrlCheck = false } = {}) {
  if (!msg || typeof msg !== 'object') return { ok: false, reason: 'corpo não é JSON' };
  for (const f of ['Type', 'MessageId', 'Timestamp', 'Signature', 'SigningCertURL', 'Message']) {
    if (!msg[f]) return { ok: false, reason: `campo ausente: ${f}` };
  }
  if (!FIELDS[msg.Type]) return { ok: false, reason: `Type desconhecido: ${msg.Type}` };
  if (!skipCertUrlCheck && !isAwsSnsUrl(msg.SigningCertURL, { requirePem: true })) {
    return { ok: false, reason: 'SigningCertURL não pertence à AWS SNS' };
  }
  const version = String(msg.SignatureVersion || '1');
  const algo = version === '2' ? 'RSA-SHA256' : version === '1' ? 'RSA-SHA1' : null;
  if (!algo) return { ok: false, reason: `SignatureVersion não suportada: ${version}` };

  let cert;
  try { cert = await fetchCert(msg.SigningCertURL); } catch (e) {
    return { ok: false, reason: `falha ao baixar certificado: ${e.message}` };
  }
  try {
    const verifier = crypto.createVerify(algo);
    verifier.update(buildStringToSign(msg), 'utf8');
    const ok = verifier.verify(cert, msg.Signature, 'base64');
    return ok ? { ok: true } : { ok: false, reason: 'assinatura inválida' };
  } catch (e) {
    return { ok: false, reason: `erro na verificação: ${e.message}` };
  }
}

async function confirmSubscription(msg, { get = httpsGet } = {}) {
  if (!isAwsSnsUrl(msg.SubscribeURL)) throw new Error('SubscribeURL não pertence à AWS SNS');
  const body = await get(msg.SubscribeURL);
  const arn = /<SubscriptionArn>([^<]+)<\/SubscriptionArn>/.exec(body);
  return { subscriptionArn: arn ? arn[1] : null };
}

module.exports = { verifySnsMessage, confirmSubscription, buildStringToSign, isAwsSnsUrl };
