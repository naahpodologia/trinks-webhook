<?php
// Testes sem dependências. Rodar com:  php tests/rodar.php
declare(strict_types=1);
require_once __DIR__ . '/../lib/Receptor.php';

$falhas = 0; $total = 0;
function teste(string $nome, callable $f): void {
    global $falhas, $total; $total++;
    try { $f(); echo "ok   $nome\n"; }
    catch (Throwable $e) { $falhas++; echo "FALHA $nome: {$e->getMessage()}\n"; }
}
function igual($esperado, $obtido, string $msg = ''): void {
    if ($esperado !== $obtido) throw new Exception("$msg esperado " . var_export($esperado, true) . ', obtido ' . var_export($obtido, true));
}
function verdade($v, string $msg = 'esperado verdadeiro'): void { if (!$v) throw new Exception($msg); }

// ---- Chave de teste e mensagens assinadas como a AWS faria ----
$chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$publica = openssl_pkey_get_details($chave)['key'];
const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-teste.pem';
const TOPICO = 'arn:aws:sns:us-east-1:123456789012:trinks-webhook-teste';
$baixar = function (string $url) use ($publica) { if ($url !== CERT_URL) throw new Exception('url inesperada'); return $publica; };

function assinar(array $m, string $versao = '1'): array {
    global $chave;
    $m['SignatureVersion'] = $versao; $m['SigningCertURL'] = CERT_URL;
    openssl_sign(Sns::textoParaAssinar($m), $sig, $chave, $versao === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1);
    $m['Signature'] = base64_encode($sig);
    return $m;
}
function notificacao(array $payload): array {
    return assinar(['Type' => 'Notification', 'MessageId' => bin2hex(random_bytes(8)), 'TopicArn' => TOPICO,
        'Subject' => 'Trinks', 'Message' => json_encode($payload), 'Timestamp' => gmdate('c')]);
}
function confirmacao(): array {
    return assinar(['Type' => 'SubscriptionConfirmation', 'MessageId' => 'c-1', 'Token' => 'tk', 'TopicArn' => TOPICO,
        'Message' => 'You have chosen to subscribe...', 'Timestamp' => gmdate('c'),
        'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=' . TOPICO . '&Token=tk']);
}
function payload(string $arq): array { return json_decode(file_get_contents(__DIR__ . "/payloads/$arq"), true); }
function pastaTemp(): string { $d = sys_get_temp_dir() . '/trinks-' . bin2hex(random_bytes(4)); mkdir($d); return $d; }

function receptor(array $cfg = [], array $deps = []): array {
    global $baixar;
    $dados = new Armazenamento(pastaTemp());
    $recebidos = new ArrayObject();
    $r = new Receptor($cfg, $dados, $deps + [
        'verificar' => fn($m) => Sns::verificar($m, $baixar),
        'confirmar' => fn($m) => ['subscriptionArn' => 'arn:teste'],
        'aoReceber' => function ($ev) use ($recebidos) { $recebidos[] = $ev; },
    ]);
    return [$r, $dados, $recebidos];
}
$enviar = fn(Receptor $r, array $m, ?string $token = null) => $r->processar(json_encode($m), $m['Type'], $token);

// ---- SNS ----
teste('aceita assinatura válida (versões 1 e 2)', function () use ($baixar) {
    foreach (['1', '2'] as $v) {
        $m = assinar(['Type' => 'Notification', 'MessageId' => 'x', 'TopicArn' => TOPICO, 'Message' => '{}', 'Timestamp' => 't'], $v);
        igual([true, null], Sns::verificar($m, $baixar));
    }
});
teste('rejeita mensagem adulterada', function () use ($baixar) {
    $m = notificacao(['TipoDeEvento' => 1, 'Action' => 1, 'ValorDaCompra' => '10']);
    $m['Message'] = str_replace('"10"', '"99999"', $m['Message']);
    igual([false, 'assinatura inválida'], Sns::verificar($m, $baixar));
});
teste('rejeita certificado fora da AWS', function () use ($baixar) {
    $m = notificacao([]); $m['SigningCertURL'] = 'https://sns.us-east-1.amazonaws.com.atacante.com/c.pem';
    igual(false, Sns::verificar($m, $baixar)[0]);
});
teste('valida URLs da AWS SNS', function () {
    verdade(Sns::urlDaAws('https://sns.sa-east-1.amazonaws.com/x.pem', true));
    verdade(!Sns::urlDaAws('http://sns.sa-east-1.amazonaws.com/x.pem'));
    verdade(!Sns::urlDaAws('https://evil.com/sns.us-east-1.amazonaws.com'));
    verdade(!Sns::urlDaAws('https://sns.sa-east-1.amazonaws.com/x.txt', true));
    verdade(!Sns::urlDaAws('https://sns.sa-east-1.amazonaws.com:8443/x.pem', true));
});
teste('confirmação chama a SubscribeURL e lê o ARN', function () {
    $chamada = null;
    $r = Sns::confirmarInscricao(confirmacao(), function ($u) use (&$chamada) { $chamada = $u;
        return '<ConfirmSubscriptionResult><SubscriptionArn>arn:aws:sns:us-east-1:1:t:abc</SubscriptionArn></ConfirmSubscriptionResult>'; });
    verdade(str_starts_with($chamada, 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription'));
    igual('arn:aws:sns:us-east-1:1:t:abc', $r['subscriptionArn']);
});
teste('confirmação recusa SubscribeURL fora da AWS', function () {
    $m = confirmacao(); $m['SubscribeURL'] = 'https://atacante.com/x';
    try { Sns::confirmarInscricao($m, fn() => ''); throw new Exception('não recusou'); } catch (RuntimeException $e) {}
});

// ---- Eventos ----
teste('converte números no formato brasileiro', function () {
    igual(13.5, Eventos::numero('13,50')); igual(1234.56, Eventos::numero('1.234,56'));
    igual(10.0, Eventos::numero('10')); igual(80.0, Eventos::numero(80)); igual(null, Eventos::numero(''));
});
teste('fechamento de conta', function () {
    $ev = Eventos::ler(['MessageId' => 'm-1', 'Message' => json_encode(payload('01-fechamento-de-conta.json'))]);
    igual('fechamento-de-conta', $ev['evento']); igual('inclusao', $ev['acao']);
    igual(180.0, $ev['resumo']['valorDaCompra']); igual('servico', $ev['resumo']['itens'][0]['tipo']);
    igual('Visa', $ev['resumo']['formasPagamento'][0]['forma']); igual('m-1', $ev['idUnico']);
});
teste('inclusão de cliente', function () {
    $ev = Eventos::ler(['MessageId' => 'm', 'Message' => json_encode(payload('03-inclusao-de-cliente.json'))]);
    igual('Nova Cliente', $ev['resumo']['nome']); igual('Balcão', $ev['resumo']['origem']);
});
teste('estoque usa IdEvento como id único', function () {
    $ev = Eventos::ler(['MessageId' => 'm', 'Message' => json_encode(payload('14-movimentacao-de-estoque.json'))]);
    igual('3f1d2c4b-0000-4000-8000-000000000014', $ev['idUnico']);
    igual(13.5, $ev['resumo']['itens'][0]['custoTotal']); igual(1234.0, $ev['resumo']['itens'][0]['estoqueApos']);
});
teste('evento desconhecido não quebra', function () {
    $ev = Eventos::ler(['MessageId' => 'm', 'Message' => '{"TipoDeEvento":99,"Action":7,"X":1}']);
    igual(false, $ev['conhecido']); igual(1, $ev['payload']['X']);
});

// ---- Receptor ----
teste('confirma inscrição automaticamente', function () use ($enviar) {
    [$r, $d] = receptor();
    [$s, $b] = $enviar($r, confirmacao());
    igual(200, $s); igual(true, $b['confirmado']);
    verdade(str_contains(file_get_contents($d->pasta() . '/inscricoes.jsonl'), 'confirmada'));
});
teste('recebe e grava os 4 exemplos', function () use ($enviar) {
    [$r, $d, $rec] = receptor();
    foreach (glob(__DIR__ . '/payloads/*.json') as $arq) {
        [$s, $b] = $enviar($r, notificacao(json_decode(file_get_contents($arq), true)));
        igual(200, $s, basename($arq)); igual(true, $b['ok'], basename($arq));
    }
    igual(4, count($rec));
    $arqs = array_map('basename', glob($d->pasta() . '/eventos/' . gmdate('Y-m-d') . '/*.jsonl')); sort($arqs);
    igual(['fechamento-de-conta.jsonl', 'inclusao-de-agendamento.jsonl', 'inclusao-de-cliente.jsonl', 'movimentacao-de-estoque.jsonl'], $arqs);
});
teste('ignora duplicados', function () use ($enviar) {
    [$r, , $rec] = receptor();
    $m = notificacao(['TipoDeEvento' => 3, 'Action' => 1]);
    $enviar($r, $m); [, $b] = $enviar($r, $m);
    igual(true, $b['duplicado']); igual(1, count($rec));
});
teste('assinatura inválida dá 403', function () use ($enviar) {
    [$r, , $rec] = receptor();
    $m = notificacao(['TipoDeEvento' => 1, 'Action' => 1]); $m['Message'] = '{"TipoDeEvento":1,"Action":3}';
    igual(403, $enviar($r, $m)[0]); igual(0, count($rec));
});
teste('exige token quando configurado', function () use ($enviar) {
    [$r] = receptor(['token' => 'segredo']);
    $m = notificacao(['TipoDeEvento' => 3, 'Action' => 1]);
    igual(401, $enviar($r, $m)[0]); igual(401, $enviar($r, $m, 'errado')[0]); igual(200, $enviar($r, $m, 'segredo')[0]);
});
teste('filtra TopicArn quando configurado', function () use ($enviar) {
    [$r] = receptor(['topicos' => ['arn:aws:sns:us-east-1:1:outro']]);
    igual(403, $enviar($r, notificacao(['TipoDeEvento' => 3, 'Action' => 1]))[0]);
});
teste('falha ao processar dá 500 e permite reenvio', function () use ($enviar) {
    $falhar = true;
    [$r] = receptor([], ['aoReceber' => function () use (&$falhar) { if ($falhar) throw new Exception('fora do ar'); }]);
    $m = notificacao(['TipoDeEvento' => 1, 'Action' => 1]);
    igual(500, $enviar($r, $m)[0]); $falhar = false; igual(200, $enviar($r, $m)[0]);
});
teste('Message ilegível responde 200 sem quebrar', function () use ($enviar) {
    [$r] = receptor();
    $m = assinar(['Type' => 'Notification', 'MessageId' => 'z', 'TopicArn' => TOPICO, 'Message' => 'não é json', 'Timestamp' => 't']);
    [$s, $b] = $enviar($r, $m); igual(200, $s); igual(false, $b['ok']);
});

// ---- Repasse (ex.: Retorne) ----
teste('sem repassarPara nada é repassado', function () use ($enviar) {
    $chamadas = 0;
    [$r] = receptor([], ['enviar' => function () use (&$chamadas) { $chamadas++; return 200; }]);
    igual(200, $enviar($r, notificacao(['TipoDeEvento' => 3, 'Action' => 1]))[0]); igual(0, $chamadas);
});
teste('repassa a mensagem idêntica, com a assinatura original válida', function () use ($baixar) {
    $recebido = null;
    [$r] = receptor(['repassarPara' => ['https://retorne.exemplo/webhook']], ['enviar' => function ($url, $corpo, $h) use (&$recebido) {
        $recebido = [$url, $corpo, $h]; return 200; }]);
    $m = notificacao(payload('01-fechamento-de-conta.json'));
    $corpo = json_encode($m);
    [$s] = $r->processar($corpo, 'Notification', null, ['x-amz-sns-subscription-arn' => 'arn:sub', 'user-agent' => 'Amazon Simple Notification Service Agent']);
    igual(200, $s);
    igual('https://retorne.exemplo/webhook', $recebido[0]);
    igual($corpo, $recebido[1], 'corpo alterado');
    igual('Notification', $recebido[2]['x-amz-sns-message-type']);
    igual($m['MessageId'], $recebido[2]['x-amz-sns-message-id']);
    igual('arn:sub', $recebido[2]['x-amz-sns-subscription-arn']);
    igual([true, null], Sns::verificar(json_decode($recebido[1], true), $baixar), 'assinatura não confere no destino');
});
teste('não repassa confirmação de inscrição', function () use ($enviar) {
    $chamadas = 0;
    [$r] = receptor(['repassarPara' => 'https://retorne.exemplo/w'], ['enviar' => function () use (&$chamadas) { $chamadas++; return 200; }]);
    igual(200, $enviar($r, confirmacao())[0]); igual(0, $chamadas);
});
teste('Retorne fora do ar: 500, e no reenvio repassa sem duplicar do nosso lado', function () use ($enviar) {
    $status = 503; $chamadas = 0;
    [$r, , $rec] = receptor(['repassarPara' => ['https://retorne.exemplo/w']], ['enviar' => function () use (&$status, &$chamadas) { $chamadas++; return $status; }]);
    $m = notificacao(['TipoDeEvento' => 11, 'Action' => 1]);
    igual(500, $enviar($r, $m)[0]);
    $status = 200;
    [$s, $b] = $enviar($r, $m);
    igual(200, $s); igual(true, $b['duplicado'] ?? false); igual(1, count($rec)); igual(2, $chamadas);
    $enviar($r, $m); igual(2, $chamadas, 'repassou duas vezes a mesma mensagem');
});
teste('repasse só conta como entregue com 200 (201, 204, 302 são reenviados)', function () use ($enviar) {
    foreach ([201, 204, 302, 400] as $cod) {
        [$r] = receptor(['repassarPara' => ['https://retorne.exemplo/w']], ['enviar' => fn() => $cod]);
        igual(500, $enviar($r, notificacao(['TipoDeEvento' => 3, 'Action' => 1]))[0], "status $cod");
    }
});
teste('erro de rede no repasse também dá 500', function () use ($enviar) {
    [$r] = receptor(['repassarPara' => ['https://retorne.exemplo/w']], ['enviar' => function () { throw new RuntimeException('timeout'); }]);
    igual(500, $enviar($r, notificacao(['TipoDeEvento' => 3, 'Action' => 1]))[0]);
});
teste('repassa mesmo evento que não conseguimos ler', function () {
    $chamadas = 0;
    [$r] = receptor(['repassarPara' => ['https://retorne.exemplo/w']], ['enviar' => function () use (&$chamadas) { $chamadas++; return 200; }]);
    $m = assinar(['Type' => 'Notification', 'MessageId' => 'q', 'TopicArn' => TOPICO, 'Message' => 'formato novo', 'Timestamp' => 't']);
    igual(200, $r->processar(json_encode($m), 'Notification', null)[0]); igual(1, $chamadas);
});
teste('ignora destino que não é https', function () use ($enviar) {
    $chamadas = 0;
    [$r] = receptor(['repassarPara' => ['http://inseguro.exemplo/w']], ['enviar' => function () use (&$chamadas) { $chamadas++; return 200; }]);
    $enviar($r, notificacao(['TipoDeEvento' => 3, 'Action' => 1])); igual(0, $chamadas);
});
teste('mensagem com assinatura inválida não é repassada', function () use ($enviar) {
    $chamadas = 0;
    [$r] = receptor(['repassarPara' => ['https://retorne.exemplo/w']], ['enviar' => function () use (&$chamadas) { $chamadas++; return 200; }]);
    $m = notificacao(['TipoDeEvento' => 1, 'Action' => 1]); $m['Message'] = '{}';
    igual(403, $enviar($r, $m)[0]); igual(0, $chamadas);
});

// ---- Banco MySQL ----
// Rodam só com um MySQL de teste:  TRINKS_TESTE_MYSQL="localhost;banco;usuario;senha" php tests/rodar.php
$mysql = getenv('TRINKS_TESTE_MYSQL') ?: '';
if ($mysql === '') {
    echo "--   testes de banco pulados (defina TRINKS_TESTE_MYSQL)\n";
} else {
    [$h, $n, $u, $sn] = explode(';', $mysql) + [null, null, null, ''];
    $cfgBanco = ['host' => $h, 'nome' => $n, 'usuario' => $u, 'senha' => $sn];
    $limpar = function () use ($cfgBanco) {
        $pdo = (new Banco($cfgBanco))->pdo();
        foreach ($pdo->query("SHOW TABLES LIKE 'trinks\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE $t");
    };
    $q = fn(string $sql) => (new Banco($cfgBanco))->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    teste('banco: grava os 4 exemplos nas tabelas organizadas', function () use ($enviar, $cfgBanco, $limpar, $q) {
        $limpar();
        $d = new Armazenamento(pastaTemp());
        $r = new Receptor(['aceitarSemAssinatura' => true, 'banco' => $cfgBanco], $d);
        foreach (glob(__DIR__ . '/payloads/*.json') as $arq) igual(200, $enviar($r, notificacao(payload(basename($arq))))[0]);
        igual(4, count($q('SELECT * FROM trinks_eventos')));
        $v = $q('SELECT * FROM trinks_vendas')[0];
        igual('180.00', $v['valor_compra']); igual('2026-11-24 10:15:15', $v['data_fechamento']); igual('Cliente Exemplo', $v['cliente_nome']);
        igual(2, count($q('SELECT * FROM trinks_venda_itens WHERE id_transacao = 778899')));
        igual('Visa', $q('SELECT forma_pagamento FROM trinks_venda_pagamentos')[0]['forma_pagamento']);
        $c = $q('SELECT * FROM trinks_clientes ORDER BY id_cliente_estabelecimento');
        igual(2, count($c)); igual('São Paulo', $c[0]['cidade']); igual('Google', $c[1]['como_nos_conheceu']); igual('(11) 91111-1111', $c[1]['telefone']);
        $e = $q('SELECT * FROM trinks_estoque_movimentacoes')[0];
        igual('-1.000', $e['quantidade']); igual('1234.000', $e['estoque_apos']); igual('13.50', $e['custo_total']); igual('445566', (string)$e['id_agendamento']);
        $a = $q('SELECT * FROM trinks_agendamentos')[0];
        igual('2026-11-28 14:00:00', $a['inicio']); igual('Agendado', $a['status']);
        igual('Cliente Exemplo', json_decode($q("SELECT payload FROM trinks_eventos WHERE evento = 'fechamento-de-conta'")[0]['payload'], true)['NomeDoCliente']);
    });
    teste('banco: reenvio e alterações não duplicam; vazio não apaga', function () use ($cfgBanco, $limpar, $q) {
        $limpar();
        $b = new Banco($cfgBanco);
        $ev = Eventos::ler(['MessageId' => 'a1', 'Message' => json_encode(payload('01-fechamento-de-conta.json'))]);
        $b->gravar($ev); $b->gravar($ev);
        igual(1, count($q('SELECT * FROM trinks_eventos'))); igual(2, count($q('SELECT * FROM trinks_venda_itens')));
        $alt = payload('03-inclusao-de-cliente.json'); $alt['TipoDeEvento'] = 4; $alt['Action'] = 2;
        $alt['IdDoClienteNoEstabelecimento'] = 5501; $alt['Email'] = ''; $alt['Nome'] = 'Nome Novo';
        $b->gravar(Eventos::ler(['MessageId' => 'a2', 'Message' => json_encode($alt)]));
        $c = $q('SELECT * FROM trinks_clientes WHERE id_cliente_estabelecimento = 5501')[0];
        igual('Nome Novo', $c['nome']); igual('cliente@exemplo.com', $c['email'], 'e-mail vazio apagou o antigo');
    });
    teste('banco: estorno marca a venda', function () use ($cfgBanco, $limpar, $q) {
        $limpar();
        $b = new Banco($cfgBanco);
        $b->gravar(Eventos::ler(['MessageId' => 'f', 'Message' => json_encode(payload('01-fechamento-de-conta.json'))]));
        $b->gravar(Eventos::ler(['MessageId' => 'e', 'Message' => json_encode(['TipoDeEvento' => 2, 'Action' => 9, 'IdDaTransacao' => 778899, 'DataDoEstorno' => '2026-11-25 09:00:00'])]));
        $v = $q('SELECT * FROM trinks_vendas')[0];
        igual(1, (int)$v['estornada']); igual('2026-11-25 09:00:00', $v['data_estorno']); igual('180.00', $v['valor_compra']);
    });
    teste('banco fora do ar: responde 200, repassa ao Retorne e grava depois', function () use ($enviar, $cfgBanco, $limpar, $q) {
        $limpar();
        $d = new Armazenamento(pastaTemp());
        $repasses = 0;
        $ruim = new Receptor(['aceitarSemAssinatura' => true, 'repassarPara' => ['https://retorne.exemplo/w'],
            'banco' => ['host' => '127.0.0.1', 'nome' => 'nao_existe', 'usuario' => 'x', 'senha' => 'errada']], $d,
            ['enviar' => function () use (&$repasses) { $repasses++; return 200; }]);
        $t0 = microtime(true);
        [$s, $b] = $enviar($ruim, notificacao(payload('03-inclusao-de-cliente.json')));
        igual(200, $s); igual(true, $b['ok']); igual(1, $repasses);
        verdade(microtime(true) - $t0 < 5, 'demorou demais com o banco fora do ar');
        igual(1, $d->quantidadePendentesBanco());
        // O banco volta: o próximo evento grava ele e o pendente
        $bom = new Receptor(['aceitarSemAssinatura' => true, 'banco' => $cfgBanco], $d);
        igual(200, $enviar($bom, notificacao(payload('01-fechamento-de-conta.json')))[0]);
        igual(0, $d->quantidadePendentesBanco()); igual(2, count($q('SELECT * FROM trinks_eventos')));
    });
    teste('banco: importar copia eventos antigos dos arquivos, sem duplicar', function () use ($enviar, $cfgBanco, $limpar, $q) {
        $limpar();
        $d = new Armazenamento(pastaTemp());
        $semBanco = new Receptor(['aceitarSemAssinatura' => true], $d);
        foreach (glob(__DIR__ . '/payloads/*.json') as $arq) $enviar($semBanco, notificacao(payload(basename($arq))));
        $g = GravadorBanco::daConfig(['banco' => $cfgBanco], $d);
        igual(4, $g->importar()['eventos']); $g->importar();
        igual(4, count($q('SELECT * FROM trinks_eventos'))); igual(1, count($q('SELECT * FROM trinks_vendas')));
    });
}


// ---- WhatsApp (Meta) ----
require_once __DIR__ . '/../lib/WhatsAppMeta.php';
function avisoWhatsApp(string $texto, string $id = 'wamid.1'): array {
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '552120519674', 'phone_number_id' => '1'],
        'contacts' => [['profile' => ['name' => 'Fulana'], 'wa_id' => '5521999998888']],
        'messages' => [['from' => '5521999998888', 'id' => $id, 'timestamp' => '1795833600', 'type' => 'text', 'text' => ['body' => $texto]]],
    ]]]]]];
}
teste('whatsapp: lê código curto e frase pronta', function () {
    igual('A7K3', WhatsAppMeta::codigo('Olá! Quero uma avaliação da minha unha (A7K3)'));
    igual('A7K3', WhatsAppMeta::codigo('ola (a7k3)'));
    igual(null, WhatsAppMeta::codigo('Oi (AI10)'), 'caracteres ambíguos');
    igual(null, WhatsAppMeta::codigo('Oi, tudo bem?'));
    verdade(WhatsAppMeta::frasePronta('Olá!  Quero marcar uma avaliação dos meus pés'));
    verdade(!WhatsAppMeta::frasePronta('Oi, quanto custa?'));
});
teste('whatsapp: guarda só contato de anúncio, sem o texto', function () {
    $c = WhatsAppMeta::contatos(avisoWhatsApp('Olá! Quero uma avaliação de micose (B2C4)'));
    igual(1, count($c));
    igual('+5521999998888', $c[0]['telefone']);
    igual('B2C4', $c[0]['codigo']);
    igual(true, $c[0]['frasePronta']);
    igual('2026-11-27 23:40:00-03:00', $c[0]['recebidoEm']);
    verdade(!str_contains(json_encode($c), 'micose'), 'texto da mensagem foi guardado');
    igual([], WhatsAppMeta::contatos(avisoWhatsApp('Bom dia, meu pé está doendo')));
});
teste('whatsapp: verificação do webhook', function () {
    $wa = new WhatsAppMeta(['verifyToken' => 'vt', 'appSecret' => 's'], new Armazenamento(pastaTemp()));
    igual([200, '123'], $wa->verificar(['hub_mode' => 'subscribe', 'hub_verify_token' => 'vt', 'hub_challenge' => '123']));
    igual(403, $wa->verificar(['hub_mode' => 'subscribe', 'hub_verify_token' => 'x', 'hub_challenge' => '123'])[0]);
});
teste('whatsapp: confere assinatura e não duplica reenvio', function () {
    $dados = new Armazenamento($pasta = pastaTemp());
    $wa = new WhatsAppMeta(['verifyToken' => 'vt', 'appSecret' => 'segredo'], $dados);
    $corpo = json_encode(avisoWhatsApp('Olá! Quero uma avaliação (A7K3)'));
    igual(403, $wa->processar($corpo, 'sha256=errada')[0]);
    $sig = 'sha256=' . hash_hmac('sha256', $corpo, 'segredo');
    igual([200, ['ok' => true, 'guardados' => 1]], $wa->processar($corpo, $sig));
    $wa->processar($corpo, $sig);
    $linhas = file(glob("$pasta/whatsapp/*.jsonl")[0]);
    igual(1, count($linhas), 'reenvio duplicou');
});

// ---- HTTP de verdade (php -S) com o index.php ----
teste('index.php: health, 404, token e evento', function () {
    $home = pastaTemp(); mkdir("$home/trinks-webhook");
    file_put_contents("$home/trinks-webhook/config.php", "<?php return ['token' => 'tk123', 'aceitarSemAssinatura' => true];");
    $porta = random_int(20000, 40000);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", __DIR__ . '/../index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p, null, ['HOME' => $home]);
    try {
        usleep(400000);
        $req = function (string $metodo, string $caminho, ?string $corpo = null) use ($porta) {
            $ctx = stream_context_create(['http' => ['method' => $metodo, 'ignore_errors' => true, 'content' => $corpo, 'header' => "Content-Type: text/plain\r\nx-amz-sns-message-type: Notification"]]);
            $b = file_get_contents("http://127.0.0.1:$porta$caminho", false, $ctx);
            preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $r);
            return [(int)$r[1], json_decode($b, true)];
        };
        igual(200, $req('GET', '/health')[0]);
        igual(404, $req('GET', '/qualquer')[0]);
        igual(405, $req('GET', '/webhook')[0]);
        $msg = json_encode(['Type' => 'Notification', 'MessageId' => 'h1', 'Message' => json_encode(payload('03-inclusao-de-cliente.json')), 'Timestamp' => 't']);
        igual(401, $req('POST', '/webhook', $msg)[0]);
        [$s, $b] = $req('POST', '/webhook?token=tk123', $msg);
        igual(200, $s); igual('inclusao-de-cliente', $b['evento']);
        verdade(is_dir("$home/trinks-webhook/dados/eventos"), 'dados não gravados fora da pasta pública');
        igual(404, $req('GET', '/whatsapp')[0], 'whatsapp sem configuração');
    } finally { proc_terminate($proc); }
});

echo "\n$total testes, " . ($total - $falhas) . " ok, $falhas falha(s)\n";
exit($falhas ? 1 : 0);
