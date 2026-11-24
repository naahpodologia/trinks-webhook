'use strict';
// Gravação simples em arquivos JSON Lines (um evento por linha).
// data/eventos/AAAA-MM-DD/<evento>.jsonl  -> eventos normalizados
// data/sns/AAAA-MM-DD.jsonl               -> todas as mensagens SNS recebidas (auditoria)
// data/vistos.txt                         -> ids já processados (evita duplicados; o SNS pode reenviar)

const fs = require('node:fs');
const path = require('node:path');

function createStorage(dataDir) {
  const seenFile = path.join(dataDir, 'vistos.txt');
  fs.mkdirSync(dataDir, { recursive: true });
  const seen = new Set(fs.existsSync(seenFile) ? fs.readFileSync(seenFile, 'utf8').split('\n').filter(Boolean) : []);

  const day = () => new Date().toISOString().slice(0, 10);
  const append = (file, obj) => {
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.appendFileSync(file, JSON.stringify(obj) + '\n');
  };

  return {
    hasSeen: (id) => seen.has(id),
    markSeen(id) { seen.add(id); fs.appendFileSync(seenFile, id + '\n'); },
    saveRaw(msg) { append(path.join(dataDir, 'sns', `${day()}.jsonl`), { recebidoEm: new Date().toISOString(), ...msg }); },
    saveEvent(ev) { append(path.join(dataDir, 'eventos', day(), `${ev.evento}.jsonl`), ev); },
    saveSubscription(info) { append(path.join(dataDir, 'inscricoes.jsonl'), { em: new Date().toISOString(), ...info }); },
  };
}

module.exports = { createStorage };
