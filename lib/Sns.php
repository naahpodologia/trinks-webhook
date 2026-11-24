<?php
// Validação de mensagens do Amazon SNS (assinatura, certificado, confirmação de inscrição).
// Referência: https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html

final class Sns
{
    private const CAMPOS = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    public static function urlDaAws(?string $url, bool $exigirPem = false): bool
    {
        if (!is_string($url) || $url === '') return false;
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
        if (isset($p['user']) || isset($p['port'])) return false;
        if (!preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $p['host'])) return false;
        if ($exigirPem && !str_ends_with($p['path'] ?? '', '.pem')) return false;
        return true;
    }

    public static function textoParaAssinar(array $m): string
    {
        $campos = self::CAMPOS[$m['Type'] ?? ''] ?? null;
        if ($campos === null) throw new InvalidArgumentException('Tipo de mensagem SNS desconhecido');
        $out = '';
        foreach ($campos as $c) {
            if (!array_key_exists($c, $m) || $m[$c] === null) continue; // Subject é opcional
            $out .= $c . "\n" . $m[$c] . "\n";
        }
        return $out;
    }

    /** @param callable(string):string $baixarCertificado */
    public static function verificar(array $m, callable $baixarCertificado): array
    {
        foreach (['Type', 'MessageId', 'Timestamp', 'Signature', 'SigningCertURL', 'Message'] as $c) {
            if (empty($m[$c]) || !is_string($m[$c])) return [false, "campo ausente: $c"];
        }
        if (!isset(self::CAMPOS[$m['Type']])) return [false, 'Type desconhecido'];
        if (!self::urlDaAws($m['SigningCertURL'], true)) return [false, 'SigningCertURL não pertence à AWS SNS'];

        $versao = (string)($m['SignatureVersion'] ?? '1');
        $algo = $versao === '2' ? OPENSSL_ALGO_SHA256 : ($versao === '1' ? OPENSSL_ALGO_SHA1 : null);
        if ($algo === null) return [false, 'SignatureVersion não suportada'];

        try {
            $cert = $baixarCertificado($m['SigningCertURL']);
        } catch (Throwable $e) {
            return [false, 'falha ao baixar certificado: ' . $e->getMessage()];
        }
        $chave = openssl_pkey_get_public($cert);
        if ($chave === false) return [false, 'certificado inválido'];
        $assinatura = base64_decode($m['Signature'], true);
        if ($assinatura === false) return [false, 'assinatura mal formada'];

        $ok = openssl_verify(self::textoParaAssinar($m), $assinatura, $chave, $algo);
        return $ok === 1 ? [true, null] : [false, 'assinatura inválida'];
    }

    /** @param callable(string):string $get */
    public static function confirmarInscricao(array $m, callable $get): array
    {
        if (!self::urlDaAws($m['SubscribeURL'] ?? null)) throw new RuntimeException('SubscribeURL não pertence à AWS SNS');
        $corpo = $get($m['SubscribeURL']);
        preg_match('#<SubscriptionArn>([^<]+)</SubscriptionArn>#', $corpo, $r);
        return ['subscriptionArn' => $r[1] ?? null];
    }

    public static function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $corpo = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($ch);
        curl_close($ch);
        if ($corpo === false) throw new RuntimeException("erro de rede: $erro");
        if ($status < 200 || $status >= 300) throw new RuntimeException("HTTP $status");
        return $corpo;
    }

    /** Baixa o certificado e guarda em cache no disco. */
    public static function baixadorComCache(string $pastaCache): callable
    {
        return function (string $url) use ($pastaCache): string {
            $arq = $pastaCache . '/cert-' . hash('sha256', $url) . '.pem';
            if (is_file($arq)) return file_get_contents($arq);
            $pem = self::httpGet($url);
            if (!str_contains($pem, '-----BEGIN')) throw new RuntimeException('certificado inválido');
            @mkdir($pastaCache, 0750, true);
            file_put_contents($arq, $pem);
            return $pem;
        };
    }
}
