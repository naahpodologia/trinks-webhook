'use strict';
// Tipos de evento e ações do webhook do Trinks (https://trinks.readme.io/reference/webhook)

const EVENT_TYPES = {
  1: { slug: 'fechamento-de-conta', nome: 'Fechamento de Conta', grupo: 'financeiro' },
  2: { slug: 'estorno-de-conta', nome: 'Estorno de Conta', grupo: 'financeiro' },
  3: { slug: 'inclusao-de-cliente', nome: 'Inclusão de Cliente', grupo: 'clientes' },
  4: { slug: 'alteracao-de-cliente', nome: 'Alteração de Cliente', grupo: 'clientes' },
  5: { slug: 'inclusao-de-profissional', nome: 'Inclusão de Profissional', grupo: 'profissionais' },
  6: { slug: 'alteracao-de-profissional', nome: 'Alteração de Profissional', grupo: 'profissionais' },
  7: { slug: 'inativacao-de-profissional', nome: 'Inativação de Profissional', grupo: 'profissionais' },
  8: { slug: 'cadastro-de-estabelecimento', nome: 'Cadastro de Estabelecimento', grupo: 'estabelecimentos' },
  9: { slug: 'exclusao-de-estabelecimento', nome: 'Exclusão de Estabelecimento', grupo: 'estabelecimentos' },
  10: { slug: 'alteracao-de-estabelecimento', nome: 'Alteração de Estabelecimento', grupo: 'estabelecimentos' },
  11: { slug: 'inclusao-de-agendamento', nome: 'Inclusão de Agendamento', grupo: 'agendamentos' },
  12: { slug: 'alteracao-de-agendamento', nome: 'Alteração de Agendamento', grupo: 'agendamentos' },
  13: { slug: 'exclusao-de-agendamento', nome: 'Exclusão de Agendamento', grupo: 'agendamentos' },
  14: { slug: 'movimentacao-de-estoque', nome: 'Movimentação de Estoque', grupo: 'estoque' },
};

const ACTIONS = { 1: 'inclusao', 2: 'alteracao', 3: 'exclusao', 9: 'estorno' };

const ITEM_TYPES = { 1: 'servico', 2: 'produto', 3: 'pacote', 4: 'vale-presente', 5: 'clube-de-assinatura', 9: 'outros' };

// Converte "13,50", "1.234,56", "10" ou 80 em número. Vazio -> null.
function toNumber(v) {
  if (v === null || v === undefined || v === '') return null;
  if (typeof v === 'number') return v;
  let s = String(v).trim();
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  const n = Number(s);
  return Number.isFinite(n) ? n : null;
}

/**
 * Lê o campo Message (JSON em texto) e devolve o evento normalizado.
 */
function parseTrinksEvent(snsMessage) {
  let payload;
  try {
    payload = typeof snsMessage.Message === 'string' ? JSON.parse(snsMessage.Message) : snsMessage.Message;
  } catch (e) {
    throw new Error(`Message não é um JSON válido: ${e.message}`);
  }
  if (!payload || typeof payload !== 'object') throw new Error('Message vazio');

  const tipo = Number(payload.TipoDeEvento);
  const action = Number(payload.Action);
  const info = EVENT_TYPES[tipo] || { slug: `desconhecido-${payload.TipoDeEvento}`, nome: 'Evento desconhecido', grupo: 'desconhecido' };

  return {
    // IdEvento existe no evento de estoque; nos demais usamos o MessageId do SNS
    idUnico: payload.IdEvento || snsMessage.MessageId,
    snsMessageId: snsMessage.MessageId,
    recebidoEm: new Date().toISOString(),
    snsTimestamp: snsMessage.Timestamp,
    subject: snsMessage.Subject || null,
    tipoDeEvento: tipo,
    evento: info.slug,
    eventoNome: info.nome,
    grupo: info.grupo,
    action,
    acao: ACTIONS[action] || `desconhecida-${payload.Action}`,
    idDoEstabelecimento: payload.IdDoEstabelecimento ?? null,
    conhecido: Boolean(EVENT_TYPES[tipo]),
    resumo: summarize(tipo, payload),
    payload, // dado bruto, sempre preservado
  };
}

// Resumo legível dos campos mais úteis por tipo de evento.
function summarize(tipo, p) {
  switch (tipo) {
    case 1:
    case 2:
      return {
        idDaTransacao: p.IdDaTransacao ?? null,
        cliente: p.NomeDoCliente ?? null,
        idDoClienteNoEstabelecimento: p.IdDoClienteNoEstabelecimento ?? null,
        data: p.DataDoFechamento ?? p.DataDoEstorno ?? null,
        valorDaCompra: toNumber(p.ValorDaCompra),
        totalServicos: toNumber(p.TotalServicos),
        totalProdutos: toNumber(p.TotalProdutos),
        totalDescontos: toNumber(p.TotalDescontos),
        totalRecebido: toNumber(p.TotalRecebido),
        profissionalQueFechou: p.NomeDoProfissionalQueFechouConta ?? null,
        itens: Array.isArray(p.Itens) ? p.Itens.map((i) => ({
          nome: i.Nome, tipo: ITEM_TYPES[i.TipoItem] || i.TipoItem,
          quantidade: toNumber(i.Quantidade), total: toNumber(i.Total), idDoAgendamento: i.IdDoAgendamento ?? null,
        })) : [],
        formasPagamento: Array.isArray(p.FormasPagamento) ? p.FormasPagamento.map((f) => ({ forma: f.FormaPagamento, valor: toNumber(f.Valor) })) : [],
      };
    case 3:
    case 4:
      return {
        idDoCliente: p.IdDoCliente ?? null,
        idDoClienteNoEstabelecimento: p.IdDoClienteNoEstabelecimento ?? null,
        nome: p.Nome ?? null,
        telefone: p.Telefone ?? null,
        email: p.Email ?? null,
        origem: p.OrigemDoCliente ?? null,
        comoNosConheceu: p.ComoNosConheceu ?? null,
        dataDeInclusao: p.DataDeInclusao ?? null,
      };
    case 14:
      return {
        origem: p.Origem ?? null,
        dataHora: p.DataHoraEventoGerado ?? null,
        itens: Array.isArray(p.Itens) ? p.Itens.map((i) => ({
          produto: i.NomeDoProduto, tipo: i.DescricaoTipoDeMovimentacao,
          quantidade: toNumber(i.Quantidade), estoqueApos: toNumber(i.EstoqueDoProdutoAposAOperacao),
          custoTotal: toNumber(i.CustoTotalDaMovimentacao),
        })) : [],
      };
    default:
      // Agendamentos, profissionais e estabelecimentos: a doc ainda não foi detalhada aqui.
      // Pegamos os campos de identificação mais prováveis; o payload completo é gravado de qualquer forma.
      return pick(p, ['IdDoAgendamento', 'IdDoCliente', 'IdDoClienteNoEstabelecimento', 'NomeDoCliente',
        'IdDoProfissional', 'IdDoProfissionalNoEstabelecimento', 'Nome', 'NomeDoProfissional',
        'DataHoraInicio', 'DataHoraFim', 'Data', 'Status', 'NomeDoServico']);
  }
}

function pick(obj, keys) {
  const out = {};
  for (const k of keys) if (obj[k] !== undefined) out[k] = obj[k];
  return out;
}

module.exports = { parseTrinksEvent, toNumber, EVENT_TYPES, ACTIONS };
