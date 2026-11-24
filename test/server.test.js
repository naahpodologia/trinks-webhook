'use strict';
const test = require('node:test');
const assert = require('node:assert');
const http = require('node:http');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createHandler, loadConfig } = require('../src/server');
const { verifySnsMessage } = require('../src/sns');
const h = require('./helpers');

async function start(envExtra = {}, depsExtra = {}) {
  const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'trinks-'));
  const config = loadConfig({ DATA_DIR: dataDir, ...envExtra });
  const recebidos = [];
  const confirmados = [];
  const handler = createHandler(config, {
    verify: (m) => verifySnsMessage(m, { fetchCert: h.fetchCert }),
    confirm: async (m) => { confirmados.push(m.SubscribeURL); return { subscriptionArn: 'arn:teste' }; },
    onEvent: async (ev) => recebidos.push(ev),
    ...depsExtra,
  });
  const server = http.createServer(handler);
  await new Promise((r) => server.listen(0, r));
  const base = `http://127.0.0.1:${server.address().port}`;
  const post = async (body, { pathname = config.webhookPath, headers = {} } = {}) => {
    const res = await fetch(base + pathname, {
      method: 'POST',
      headers: { 'content-type': 'text/plain; charset=UTF-8', 'x-amz-sns-message-type': body.Type, ...headers },
      body: JSON.stringify(body),
    });
    return { status: res.status, json: await res.json() };
  };
  return { server, base, post, dataDir, recebidos, confirmados, close: () => server.close() };
}

test('confirma inscrição automaticamente', async () => {
  const s = await start();
  const r = await s.post(h.subscriptionConfirmation());
  assert.strictEqual(r.status, 200);
  assert.strictEqual(r.json.confirmado, true);
  assert.strictEqual(s.confirmados.length, 1);
  assert.match(fs.readFileSync(path.join(s.dataDir, 'inscricoes.jsonl'), 'utf8'), /confirmada/);
  s.close();
});

test('recebe e grava os eventos de exemplo', async () => {
  const s = await start();
  const dir = path.join(__dirname, 'payloads');
  for (const f of fs.readdirSync(dir)) {
    const r = await s.post(h.notification(JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'))));
    assert.strictEqual(r.status, 200, f);
    assert.strictEqual(r.json.ok, true, f);
  }
  assert.strictEqual(s.recebidos.length, 4);
  const dia = new Date().toISOString().slice(0, 10);
  const arquivos = fs.readdirSync(path.join(s.dataDir, 'eventos', dia)).sort();
  assert.deepStrictEqual(arquivos, ['fechamento-de-conta.jsonl', 'inclusao-de-agendamento.jsonl', 'inclusao-de-cliente.jsonl', 'movimentacao-de-estoque.jsonl']);
  s.close();
});

test('ignora duplicados (o SNS pode reenviar)', async () => {
  const s = await start();
  const m = h.notification({ TipoDeEvento: 3, Action: 1, Nome: 'X' });
  await s.post(m);
  const r = await s.post(m);
  assert.strictEqual(r.json.duplicado, true);
  assert.strictEqual(s.recebidos.length, 1);
  s.close();
});

test('rejeita assinatura inválida com 403', async () => {
  const s = await start();
  const m = h.notification({ TipoDeEvento: 1, Action: 1 });
  m.Message = '{"TipoDeEvento":1,"Action":3}';
  const r = await s.post(m);
  assert.strictEqual(r.status, 403);
  assert.strictEqual(s.recebidos.length, 0);
  s.close();
});

test('exige token na URL quando configurado', async () => {
  const s = await start({ WEBHOOK_TOKEN: 'segredo' });
  const m = h.notification({ TipoDeEvento: 3, Action: 1 });
  assert.strictEqual((await s.post(m)).status, 401);
  assert.strictEqual((await s.post(m, { pathname: '/webhook/trinks?token=segredo' })).status, 200);
  s.close();
});

test('filtra TopicArn quando configurado', async () => {
  const s = await start({ ALLOWED_TOPIC_ARNS: 'arn:aws:sns:us-east-1:1:outro' });
  assert.strictEqual((await s.post(h.notification({ TipoDeEvento: 3, Action: 1 }))).status, 403);
  s.close();
});

test('erro ao processar devolve 500 para o SNS tentar de novo, e não marca como visto', async () => {
  let falhar = true;
  const s = await start({}, { onEvent: async () => { if (falhar) throw new Error('planilha fora do ar'); } });
  const m = h.notification({ TipoDeEvento: 1, Action: 1 });
  assert.strictEqual((await s.post(m)).status, 500);
  falhar = false;
  assert.strictEqual((await s.post(m)).status, 200);
  s.close();
});

test('health check', async () => {
  const s = await start();
  const r = await fetch(s.base + '/health');
  assert.strictEqual(r.status, 200);
  s.close();
});
