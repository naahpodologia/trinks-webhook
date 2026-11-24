<?php
// Receptor do webhook do Trinks (Amazon SNS) para a Naah Podologia.
// Endpoint:  POST https://<subdominio>/webhook?token=<token>
// Saúde:     GET  https://<subdominio>/health

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
