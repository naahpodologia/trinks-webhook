'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { parseTrinksEvent, toNumber } = require('../src/events');
const load = (f) => require(`./payloads/${f}`);
const wrap = (p) => ({ MessageId: 'm-1', Timestamp: 't', Message: JSON.stringify(p) });

test('converte números no formato brasileiro', () => {
  assert.strictEqual(toNumber('13,50'), 13.5);
  assert.strictEqual(toNumber('1.234,56'), 1234.56);
  assert.strictEqual(toNumber('10'), 10);
  assert.strictEqual(toNumber(80), 80);
  assert.strictEqual(toNumber(''), null);
});

test('fechamento de conta', () => {
  const ev = parseTrinksEvent(wrap(load('01-fechamento-de-conta.json')));
  assert.strictEqual(ev.evento, 'fechamento-de-conta');
  assert.strictEqual(ev.acao, 'inclusao');
  assert.strictEqual(ev.resumo.valorDaCompra, 180);
  assert.strictEqual(ev.resumo.itens[0].tipo, 'servico');
  assert.strictEqual(ev.resumo.formasPagamento[0].forma, 'Visa');
  assert.strictEqual(ev.idUnico, 'm-1');
});

test('inclusão de cliente', () => {
  const ev = parseTrinksEvent(wrap(load('03-inclusao-de-cliente.json')));
  assert.strictEqual(ev.evento, 'inclusao-de-cliente');
  assert.strictEqual(ev.resumo.nome, 'Nova Cliente');
  assert.strictEqual(ev.resumo.origem, 'Balcão');
});

test('movimentação de estoque usa IdEvento como id único', () => {
  const ev = parseTrinksEvent(wrap(load('14-movimentacao-de-estoque.json')));
  assert.strictEqual(ev.evento, 'movimentacao-de-estoque');
  assert.strictEqual(ev.idUnico, '3f1d2c4b-0000-4000-8000-000000000014');
  assert.strictEqual(ev.resumo.itens[0].custoTotal, 13.5);
  assert.strictEqual(ev.resumo.itens[0].estoqueApos, 1234);
});

test('evento desconhecido não quebra e guarda o payload', () => {
  const ev = parseTrinksEvent(wrap({ TipoDeEvento: 99, Action: 7, X: 1 }));
  assert.strictEqual(ev.conhecido, false);
  assert.strictEqual(ev.payload.X, 1);
});

test('Message inválido gera erro claro', () => {
  assert.throws(() => parseTrinksEvent({ Message: 'não é json' }), /Message não é um JSON válido/);
});
