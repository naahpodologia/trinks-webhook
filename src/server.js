'use strict';
// Receptor do webhook do Trinks. Sem dependências externas: roda com `node src/server.js`.

const http = require('node:http');
const path = require('node:path');
const { verifySnsMessage, confirmSubscription } = require('./sns');
const { parseTrinksEvent } = require('./events');
const { createStorage } = require('./storage');

function loadConfig(env = process.env) {
  return {
    port: Number(env.PORT || 3000),
    webhookPath: env.WEBHOOK_PATH || '/webhook/trinks',
    webhookToken: env.WEBHOOK_TOKEN || '',
    dataDir: path.resolve(env.DATA_DIR || './data'),
    allowedTopicArns: (env.ALLOWED_TOPIC_ARNS || '').split(',').map((s) => s.trim()).filter(Boolean),
    autoConfirm: (env.AUTO_CONFIRM_SUBSCRIPTION || 'true') !== 'false',
    allowUnsigned: env.ALLOW_UNSIGNED === 'true',
  };
}

const log = (nivel, msg, extra = {}) =>
  console.log(JSON.stringify({ em: new Date().toISOString(), nivel, msg, ...extra }));

function readBody(req, limit = 1024 * 1024) {
  return new Promise((resolve, reject) => {
    let size = 0; const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > limit) { reject(new Error('corpo muito grande')); req.destroy(); return; }
      chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

/**
 * Cria o handler HTTP. `deps` permite trocar a verificação/confirmação nos testes.
 * `onEvent(evento)` é o ponto para plugar o que fazer com os dados (planilha, banco, CRM...).
 */
function createHandler(config, deps = {}) {
  const storage = deps.storage || createStorage(config.dataDir);
  const verify = deps.verify || ((m) => verifySnsMessage(m));
  const confirm = deps.confirm || ((m) => confirmSubscription(m));
  const onEvent = deps.onEvent || (async () => {});

  const send = (res, status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify(body));
  };

  return async function handler(req, res) {
    const url = new URL(req.url, 'http://localhost');

    if (req.method === 'GET' && url.pathname === '/health') return send(res, 200, { ok: true });
    if (url.pathname !== config.webhookPath) return send(res, 404, { erro: 'não encontrado' });
    if (req.method !== 'POST') return send(res, 405, { erro: 'use POST' });
    if (config.webhookToken && url.searchParams.get('token') !== config.webhookToken) {
      log('aviso', 'token inválido na URL', { ip: req.socket.remoteAddress });
      return send(res, 401, { erro: 'token inválido' });
    }

    let msg;
    try {
      // O SNS envia Content-Type text/plain, por isso lemos o corpo cru e fazemos o parse aqui.
      msg = JSON.parse(await readBody(req));
    } catch (e) {
      return send(res, 400, { erro: 'JSON inválido' });
    }

    const headerType = req.headers['x-amz-sns-message-type'];
    if (headerType && msg.Type && headerType !== msg.Type) {
      return send(res, 400, { erro: 'tipo de mensagem inconsistente' });
    }

    // 1) Assinatura
    if (config.allowUnsigned) {
      log('aviso', 'ALLOW_UNSIGNED=true: assinatura NÃO verificada (somente teste local)');
    } else {
      const v = await verify(msg);
      if (!v.ok) {
        log('aviso', 'mensagem rejeitada', { motivo: v.reason, messageId: msg.MessageId });
        return send(res, 403, { erro: 'assinatura inválida' });
      }
    }

    // 2) Tópico permitido
    if (config.allowedTopicArns.length && !config.allowedTopicArns.includes(msg.TopicArn)) {
      log('aviso', 'TopicArn não permitido', { topicArn: msg.TopicArn });
      return send(res, 403, { erro: 'tópico não permitido' });
    }

    storage.saveRaw(msg);

    // 3) Confirmação de inscrição
    if (msg.Type === 'SubscriptionConfirmation') {
      log('info', 'pedido de confirmação de inscrição recebido', { topicArn: msg.TopicArn });
      if (!config.autoConfirm) {
        storage.saveSubscription({ status: 'pendente', topicArn: msg.TopicArn, subscribeURL: msg.SubscribeURL });
        return send(res, 200, { ok: true, confirmado: false });
      }
      try {
        const r = await confirm(msg);
        storage.saveSubscription({ status: 'confirmada', topicArn: msg.TopicArn, ...r });
        log('info', 'inscrição confirmada', r);
        return send(res, 200, { ok: true, confirmado: true });
      } catch (e) {
        log('erro', 'falha ao confirmar inscrição', { erro: e.message });
        return send(res, 500, { erro: 'falha ao confirmar' });
      }
    }

    if (msg.Type === 'UnsubscribeConfirmation') {
      storage.saveSubscription({ status: 'cancelada', topicArn: msg.TopicArn });
      log('aviso', 'inscrição cancelada pelo SNS', { topicArn: msg.TopicArn });
      return send(res, 200, { ok: true });
    }

    if (msg.Type !== 'Notification') return send(res, 400, { erro: `Type não suportado: ${msg.Type}` });

    // 4) Evento do Trinks
    let ev;
    try {
      ev = parseTrinksEvent(msg);
    } catch (e) {
      // Erro permanente: responder 200 para o SNS não reenviar sem fim; a mensagem crua já foi gravada.
      log('erro', 'evento ilegível', { erro: e.message, messageId: msg.MessageId });
      return send(res, 200, { ok: false, erro: 'evento ilegível' });
    }

    if (storage.hasSeen(ev.idUnico)) {
      log('info', 'evento duplicado ignorado', { id: ev.idUnico });
      return send(res, 200, { ok: true, duplicado: true });
    }

    try {
      storage.saveEvent(ev);
      await onEvent(ev);
      storage.markSeen(ev.idUnico);
    } catch (e) {
      // Erro temporário: 500 faz o SNS tentar de novo mais tarde.
      log('erro', 'falha ao processar evento', { erro: e.message, id: ev.idUnico });
      return send(res, 500, { erro: 'falha ao processar' });
    }

    log('info', 'evento recebido', { evento: ev.evento, acao: ev.acao, id: ev.idUnico, estabelecimento: ev.idDoEstabelecimento });
    return send(res, 200, { ok: true, evento: ev.evento, acao: ev.acao });
  };
}

if (require.main === module) {
  const config = loadConfig();
  const handler = createHandler(config);
  http.createServer((req, res) => {
    handler(req, res).catch((e) => {
      log('erro', 'erro inesperado', { erro: e.message });
      if (!res.headersSent) { res.writeHead(500); res.end(); }
    });
  }).listen(config.port, () => {
    log('info', 'receptor do webhook do Trinks no ar', { porta: config.port, caminho: config.webhookPath, dados: config.dataDir });
    if (config.allowUnsigned) log('aviso', 'ALLOW_UNSIGNED está ligado. Desligue antes de ir para produção.');
  });
}

module.exports = { createHandler, loadConfig };
