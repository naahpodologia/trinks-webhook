<?php
// Webhook do WhatsApp (Meta Cloud API) para ligar o telefone de quem chamou no WhatsApp
// ao clique no anúncio do Google.
//
// Este receptor só LÊ: não envia mensagens e não mexe na conta. Ele recebe o mesmo aviso de
// "mensagem recebida" que a Meta manda para os outros apps inscritos na conta (ex.: Retorne).
//
// Privacidade: o texto da mensagem é descartado. Só é guardado o que liga a conversa ao anúncio:
// telefone, hora, código curto do anúncio (ex.: "(A7K3)") e se a frase pronta do site foi usada.
// Mensagens sem código e sem frase pronta não são guardadas.
//
// Gravação: <dados>/whatsapp/AAAA-MM-DD.jsonl (um contato por linha).

require_once __DIR__ . '/Armazenamento.php';

final class WhatsAppMeta
{
    // Alfabeto sem caracteres ambíguos (sem I, L, O, 0, 1).
    public const ALFABETO_CODIGO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    // Começos das frases prontas dos botões do site (sem acento e em minúsculas para comparar).
    public const FRASES_PRONTAS = ['ola! quero uma avaliacao', 'ola! quero marcar uma avaliacao'];

    public function __construct(private array $cfg, private Armazenamento $dados) {}

    public function configurado(): bool
    {
        return ($this->cfg['verifyToken'] ?? '') !== '' && ($this->cfg['appSecret'] ?? '') !== '';
    }

    /**
     * Verificação do webhook (GET com hub.mode, hub.verify_token, hub.challenge).
     * @return array{0:int,1:string} status e corpo em texto
     */
    public function verificar(array $query): array
    {
        $esperado = (string)($this->cfg['verifyToken'] ?? '');
        $modo = (string)($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string)($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $desafio = (string)($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');
        if ($esperado === '' || $modo !== 'subscribe' || !hash_equals($esperado, $token)) return [403, 'proibido'];
        return [200, $desafio];
    }

    public function assinaturaValida(string $corpo, ?string $cabecalho): bool
    {
        $segredo = (string)($this->cfg['appSecret'] ?? '');
        if ($segredo === '' || !$cabecalho || !str_starts_with($cabecalho, 'sha256=')) return false;
        return hash_equals('sha256=' . hash_hmac('sha256', $corpo, $segredo), $cabecalho);
    }

    /**
     * Aviso da Meta (POST). Responde 200 sempre que a assinatura confere, para a Meta não reenviar à toa.
     * @return array{0:int,1:array}
     */
    public function processar(string $corpo, ?string $assinatura): array
    {
        if (!$this->assinaturaValida($corpo, $assinatura)) {
            $this->dados->log('aviso', 'whatsapp: assinatura inválida');
            return [403, ['erro' => 'assinatura inválida']];
        }
        $json = json_decode($corpo, true);
        if (!is_array($json)) return [200, ['ok' => true, 'guardados' => 0]];

        $guardados = 0;
        foreach (self::contatos($json) as $c) {
            $this->dados->salvarWhatsApp($c);
            $guardados++;
        }
        // Registro mínimo para diagnóstico: só contagens, nunca telefone nem texto.
        $this->dados->log('info', 'whatsapp: aviso recebido', ['mensagens' => self::contarMensagens($json), 'guardados' => $guardados]);
        return [200, ['ok' => true, 'guardados' => $guardados]];
    }

    public static function contarMensagens(array $json): int
    {
        $n = 0;
        foreach ($json['entry'] ?? [] as $e) foreach ($e['changes'] ?? [] as $m) $n += count((array)($m['value']['messages'] ?? []));
        return $n;
    }

    /** Extrai das mensagens recebidas só os contatos que vieram de anúncio (código ou frase pronta). */
    public static function contatos(array $json): array
    {
        $saida = [];
        foreach ($json['entry'] ?? [] as $entrada) {
            foreach ($entrada['changes'] ?? [] as $mudanca) {
                if (($mudanca['field'] ?? '') !== 'messages') continue;
                $valor = $mudanca['value'] ?? [];
                foreach ($valor['messages'] ?? [] as $m) {
                    $texto = (string)($m['text']['body'] ?? $m['button']['text'] ?? '');
                    $codigo = self::codigo($texto);
                    $frase = self::frasePronta($texto);
                    if ($codigo === null && !$frase) continue;
                    $saida[] = [
                        'idMensagem' => (string)($m['id'] ?? ''),
                        'telefone' => self::telefone((string)($m['from'] ?? '')),
                        'recebidoEm' => self::horaBrasilia((int)($m['timestamp'] ?? 0)),
                        'codigo' => $codigo,
                        'frasePronta' => $frase,
                        'numeroDaClinica' => (string)($valor['metadata']['display_phone_number'] ?? ''),
                    ];
                }
            }
        }
        return $saida;
    }

    /** Código curto entre parênteses, ex.: "Olá! Quero uma avaliação (A7K3)". */
    public static function codigo(string $texto): ?string
    {
        $a = preg_quote(self::ALFABETO_CODIGO, '/');
        return preg_match('/\(\s*([' . $a . ']{4})\s*\)/u', mb_strtoupper($texto), $r) ? $r[1] : null;
    }

    public static function frasePronta(string $texto): bool
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);
        $t = preg_replace('/\s+/', ' ', $t);
        foreach (self::FRASES_PRONTAS as $f) if (str_starts_with($t, $f)) return true;
        return false;
    }

    /** Telefone no formato E.164 (+55...), como o Google Ads pede. */
    public static function telefone(string $waId): string
    {
        $d = preg_replace('/\D/', '', $waId);
        return $d === '' ? '' : '+' . $d;
    }

    public static function horaBrasilia(int $unix): string
    {
        $t = $unix > 0 ? $unix : time();
        return (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:sP');
    }
}
