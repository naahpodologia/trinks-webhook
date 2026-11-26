<?php
// Lógica do receptor, separada do HTTP para poder ser testada.

require_once __DIR__ . '/Sns.php';
require_once __DIR__ . '/Eventos.php';
require_once __DIR__ . '/Armazenamento.php';
require_once __DIR__ . '/GravadorBanco.php';

final class Receptor
{
    /** @var callable */ private $verificar;
    /** @var callable */ private $confirmar;
    /** @var callable */ private $aoReceber;
    /** @var callable */ private $enviar;
    private ?GravadorBanco $banco;

    /**
     * $cfg: token, topicos (array), confirmarAutomaticamente (bool), aceitarSemAssinatura (bool)
     * $deps: verificar(array):array, confirmar(array):array, aoReceber(array):void, banco (GravadorBanco|null)
     */
    public function __construct(private array $cfg, private Armazenamento $dados, array $deps = [])
    {
        $cache = $dados->pasta() . '/cache';
        $this->verificar = $deps['verificar'] ?? fn(array $m) => Sns::verificar($m, Sns::baixadorComCache($cache));
        $this->confirmar = $deps['confirmar'] ?? fn(array $m) => Sns::confirmarInscricao($m, [Sns::class, 'httpGet']);
        // Ponto para plugar o destino dos dados (planilha, banco, CRM...).
        $this->aoReceber = $deps['aoReceber'] ?? function (array $ev): void {};
        // Envio HTTP usado no repasse: fn(string $url, string $corpo, array $cabecalhos): int (status HTTP)
        $this->enviar = $deps['enviar'] ?? [self::class, 'postar'];
        // Banco MySQL (opcional; ligado quando 'banco' estiver no config.php)
        $this->banco = array_key_exists('banco', $deps) ? $deps['banco'] : GravadorBanco::daConfig($cfg, $dados);
    }

    /** @return array{0:int,1:array} [status HTTP, corpo] */
    public function processar(string $corpo, ?string $tipoNoCabecalho, ?string $tokenNaUrl, array $cabecalhos = []): array
    {
        $token = (string)($this->cfg['token'] ?? '');
        if ($token !== '' && !hash_equals($token, (string)$tokenNaUrl)) {
            $this->dados->log('aviso', 'token inválido na URL');
            return [401, ['erro' => 'token inválido']];
        }

        $m = json_decode($corpo, true);
        if (!is_array($m)) return [400, ['erro' => 'JSON inválido']];

        if ($tipoNoCabecalho && isset($m['Type']) && $tipoNoCabecalho !== $m['Type']) {
            return [400, ['erro' => 'tipo de mensagem inconsistente']];
        }

        // 1) Assinatura
        if (!empty($this->cfg['aceitarSemAssinatura'])) {
            $this->dados->log('aviso', 'aceitarSemAssinatura ligado: assinatura NÃO verificada (somente teste)');
        } else {
            [$ok, $motivo] = ($this->verificar)($m);
            if (!$ok) {
                $this->dados->log('aviso', 'mensagem rejeitada', ['motivo' => $motivo, 'messageId' => $m['MessageId'] ?? null]);
                return [403, ['erro' => 'assinatura inválida']];
            }
        }

        // 2) Tópico permitido
        $topicos = $this->cfg['topicos'] ?? [];
        if ($topicos && !in_array($m['TopicArn'] ?? '', $topicos, true)) {
            $this->dados->log('aviso', 'TopicArn não permitido', ['topicArn' => $m['TopicArn'] ?? null]);
            return [403, ['erro' => 'tópico não permitido']];
        }

        $this->dados->salvarBruto($m);
        $tipo = $m['Type'] ?? '';

        // 3) Confirmação de inscrição
        if ($tipo === 'SubscriptionConfirmation') {
            if (($this->cfg['confirmarAutomaticamente'] ?? true) === false) {
                $this->dados->salvarInscricao(['status' => 'pendente', 'topicArn' => $m['TopicArn'] ?? null, 'subscribeURL' => $m['SubscribeURL'] ?? null]);
                return [200, ['ok' => true, 'confirmado' => false]];
            }
            try {
                $r = ($this->confirmar)($m);
                $this->dados->salvarInscricao(['status' => 'confirmada', 'topicArn' => $m['TopicArn'] ?? null] + $r);
                $this->dados->log('info', 'inscrição confirmada', $r);
                return [200, ['ok' => true, 'confirmado' => true]];
            } catch (Throwable $e) {
                $this->dados->log('erro', 'falha ao confirmar inscrição', ['erro' => $e->getMessage()]);
                return [500, ['erro' => 'falha ao confirmar']];
            }
        }

        if ($tipo === 'UnsubscribeConfirmation') {
            $this->dados->salvarInscricao(['status' => 'cancelada', 'topicArn' => $m['TopicArn'] ?? null]);
            return [200, ['ok' => true]];
        }

        if ($tipo !== 'Notification') return [400, ['erro' => 'Type não suportado']];

        // 4) Evento do Trinks (nosso processamento) e 5) repasse a outros sistemas (ex.: Retorne)
        [$resultado, $evNovo] = $this->processarEvento($m);
        $repassou = $this->repassar($m, $corpo, $cabecalhos);
        // 6) Banco MySQL: só depois do repasse, e uma falha aqui nunca muda a resposta
        //    (o evento fica na fila de pendentes e é gravado depois).
        if ($evNovo && $this->banco) $this->banco->gravar($evNovo);
        if (!$repassou) {
            // 500 faz o SNS reenviar. Do nosso lado o evento duplicado é ignorado; o repasse é tentado de novo.
            return [500, ['erro' => 'falha ao repassar']];
        }
        return $resultado;
    }

    /** @return array{0:array,1:?array} [[status, corpo], evento novo gravado (ou null)] */
    private function processarEvento(array $m): array
    {
        // 4) Evento do Trinks
        try {
            $ev = Eventos::ler($m);
        } catch (Throwable $e) {
            // Erro permanente: 200 para o SNS não reenviar sem fim. A mensagem crua já foi gravada.
            $this->dados->log('erro', 'evento ilegível', ['erro' => $e->getMessage(), 'messageId' => $m['MessageId'] ?? null]);
            return [[200, ['ok' => false, 'erro' => 'evento ilegível']], null];
        }

        if ($this->dados->jaVisto($ev['idUnico'])) {
            return [[200, ['ok' => true, 'duplicado' => true]], null];
        }

        try {
            $this->dados->salvarEvento($ev);
            ($this->aoReceber)($ev);
            $this->dados->marcarVisto($ev['idUnico']);
        } catch (Throwable $e) {
            // Erro temporário: 500 faz o SNS tentar de novo.
            $this->dados->log('erro', 'falha ao processar evento', ['erro' => $e->getMessage(), 'id' => $ev['idUnico']]);
            return [[500, ['erro' => 'falha ao processar']], null];
        }

        $this->dados->log('info', 'evento recebido', ['evento' => $ev['evento'], 'acao' => $ev['acao'], 'id' => $ev['idUnico']]);
        return [[200, ['ok' => true, 'evento' => $ev['evento'], 'acao' => $ev['acao']]], $ev];
    }

    /** Destinos configurados em 'repassarPara' (só https). */
    private function destinos(): array
    {
        $d = $this->cfg['repassarPara'] ?? [];
        $d = is_string($d) ? [$d] : (is_array($d) ? $d : []);
        return array_values(array_filter($d, fn($u) => is_string($u) && str_starts_with($u, 'https://')));
    }

    /**
     * Repassa a mensagem SNS, byte a byte, para cada destino. A assinatura original continua válida,
     * porque ela cobre o conteúdo da mensagem e não o endereço de entrega.
     * Cada destino recebe cada MessageId uma única vez (controle em repassados.txt).
     */
    private function repassar(array $m, string $corpo, array $cabecalhos): bool
    {
        $id = (string)($m['MessageId'] ?? '');
        $tudoOk = true;
        foreach ($this->destinos() as $url) {
            $chave = substr(hash('sha256', $url), 0, 12) . ' ' . $id;
            if ($this->dados->jaRepassado($chave)) continue;
            $h = array_filter([
                'Content-Type' => 'text/plain; charset=UTF-8',
                'x-amz-sns-message-type' => $m['Type'] ?? null,
                'x-amz-sns-message-id' => $id,
                'x-amz-sns-topic-arn' => $m['TopicArn'] ?? null,
                'x-amz-sns-subscription-arn' => $cabecalhos['x-amz-sns-subscription-arn'] ?? null,
                'x-amz-sns-rawdelivery' => $cabecalhos['x-amz-sns-rawdelivery'] ?? null,
                'User-Agent' => $cabecalhos['user-agent'] ?? 'Amazon Simple Notification Service Agent',
            ]);
            try {
                $status = ($this->enviar)($url, $corpo, $h);
            } catch (Throwable $e) {
                $status = 0;
                $this->dados->log('erro', 'repasse falhou', ['destino' => parse_url($url, PHP_URL_HOST), 'erro' => $e->getMessage(), 'messageId' => $id]);
            }
            if ($status === 200) { // o Retorne só considera sucesso o 200; qualquer outro status, erro de rede ou timeout é reenviado
                $this->dados->marcarRepassado($chave);
                $this->dados->log('info', 'repassado', ['destino' => parse_url($url, PHP_URL_HOST), 'status' => $status, 'messageId' => $id]);
            } else {
                $tudoOk = false;
                if ($status) $this->dados->log('erro', 'repasse recusado', ['destino' => parse_url($url, PHP_URL_HOST), 'status' => $status, 'messageId' => $id]);
            }
        }
        return $tudoOk;
    }

    public static function postar(string $url, string $corpo, array $cabecalhos): int
    {
        $ch = curl_init($url);
        $linhas = [];
        foreach ($cabecalhos as $k => $v) $linhas[] = "$k: $v";
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_HTTPHEADER => $linhas,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        $erro = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 0) throw new RuntimeException($erro ?: 'sem resposta');
        return $status;
    }
}
