<?php
// Receptor do webhook do Trinks (Amazon SNS) para a Naah Podologia.
// Endpoint:  POST https://<subdominio>/webhook?token=<token>
// Saúde:     GET  https://<subdominio>/health
// Banco:     GET  https://<subdominio>/banco?token=<token>            (situação do banco)
//            GET  https://<subdominio>/banco/importar?token=<token>   (copia para o banco os eventos já guardados em arquivo)
// WhatsApp:  GET/POST https://<subdominio>/whatsapp   (webhook da Meta; só com o bloco whatsapp no config.php)

declare(strict_types=1);
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Receptor.php';

function responder(int $status, array $corpo): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
}

$cfg = Config::carregar(__DIR__);
$caminho = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($caminho === '/health' && $metodo === 'GET') {
    try {
        new Armazenamento($cfg['pastaDados']);
        responder(200, ['ok' => true]);
    } catch (Throwable $e) {
        responder(500, ['ok' => false, 'erro' => 'pasta de dados indisponível']);
    }
    exit;
}

if (($caminho === '/banco' || $caminho === '/banco/importar') && $metodo === 'GET') {
    $token = (string)($cfg['token'] ?? '');
    if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) { responder(401, ['erro' => 'token inválido']); exit; }
    if (!Banco::configurado($cfg)) { responder(200, ['banco' => 'não configurado no config.php']); exit; }
    try {
        $dados = new Armazenamento($cfg['pastaDados']);
        $g = GravadorBanco::daConfig($cfg, $dados);
        if ($caminho === '/banco/importar') {
            @set_time_limit(300);
            responder(200, ['ok' => true, 'importados' => $g->importar(), 'pendentes' => $dados->quantidadePendentesBanco()]);
        } else {
            $g->reprocessarPendentes();
            $pdo = (new Banco($cfg['banco'], $dados->pasta()))->pdo();
            $contagem = [];
            foreach (['trinks_eventos', 'trinks_clientes', 'trinks_vendas', 'trinks_agendamentos', 'trinks_profissionais', 'trinks_estoque_movimentacoes'] as $t) {
                $contagem[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            }
            responder(200, ['ok' => true, 'banco' => 'conectado', 'registros' => $contagem, 'pendentes' => $dados->quantidadePendentesBanco()]);
        }
    } catch (Throwable $e) {
        responder(500, ['ok' => false, 'banco' => 'erro', 'erro' => GravadorBanco::resumirErro($e)]);
    }
    exit;
}

if ($caminho === '/whatsapp') {
    require_once __DIR__ . '/lib/WhatsAppMeta.php';
    try {
        $wa = new WhatsAppMeta((array)($cfg['whatsapp'] ?? []), new Armazenamento($cfg['pastaDados']));
        if (!$wa->configurado()) { responder(404, ['erro' => 'não encontrado']); exit; }
        if ($metodo === 'GET') {
            [$status, $texto] = $wa->verificar($_GET);
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
            echo $texto;
            exit;
        }
        if ($metodo !== 'POST') { responder(405, ['erro' => 'use POST']); exit; }
        $corpo = file_get_contents('php://input', false, null, 0, 1024 * 1024) ?: '';
        [$status, $resposta] = $wa->processar($corpo, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null);
        responder($status, $resposta);
    } catch (Throwable $e) {
        error_log('trinks-webhook whatsapp: ' . $e->getMessage());
        responder(500, ['erro' => 'erro interno']);
    }
    exit;
}

if ($caminho !== rtrim($cfg['caminho'], '/')) { responder(404, ['erro' => 'não encontrado']); exit; }
if ($metodo !== 'POST') { responder(405, ['erro' => 'use POST']); exit; }

$corpo = file_get_contents('php://input', false, null, 0, 1024 * 1024) ?: '';

try {
    $dados = new Armazenamento($cfg['pastaDados']);
    $receptor = new Receptor($cfg, $dados);
    [$status, $resposta] = $receptor->processar(
        $corpo,
        $_SERVER['HTTP_X_AMZ_SNS_MESSAGE_TYPE'] ?? null,
        isset($_GET['token']) ? (string)$_GET['token'] : null,
        [
            'x-amz-sns-subscription-arn' => $_SERVER['HTTP_X_AMZ_SNS_SUBSCRIPTION_ARN'] ?? null,
            'x-amz-sns-rawdelivery' => $_SERVER['HTTP_X_AMZ_SNS_RAWDELIVERY'] ?? null,
            'user-agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]
    );
    responder($status, $resposta);
} catch (Throwable $e) {
    error_log('trinks-webhook: ' . $e->getMessage());
    responder(500, ['erro' => 'erro interno']);
}
