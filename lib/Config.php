<?php
// Lê a configuração. Procura, nesta ordem:
//   1. /home/<usuario>/trinks-webhook/config.php  (recomendado: fora da pasta pública)
//   2. config.php ao lado do index.php            (bloqueado pelo .htaccess)
// Sem arquivo, usa os padrões (a assinatura da AWS continua sendo conferida).

final class Config
{
    public static function pastaHome(string $base): ?string
    {
        $home = getenv('HOME');
        if ($home && is_dir($home)) return rtrim($home, '/');
        if (preg_match('#^(/home/[^/]+)/#', $base . '/', $r)) return $r[1];
        return null;
    }

    public static function carregar(string $base): array
    {
        $home = self::pastaHome($base);
        $padrao = [
            'token' => '',
            'caminho' => '/webhook',
            'pastaDados' => $home ? $home . '/trinks-webhook/dados' : $base . '/dados',
            'topicos' => [],
            'confirmarAutomaticamente' => true,
            'aceitarSemAssinatura' => false,
        ];
        foreach (array_filter([$home ? $home . '/trinks-webhook/config.php' : null, $base . '/config.php']) as $arq) {
            if (is_file($arq)) {
                $c = require $arq;
                if (is_array($c)) return array_merge($padrao, $c, ['arquivo' => $arq]);
            }
        }
        return $padrao + ['arquivo' => null];
    }
}
