<?php
// Lógica do receptor, separada do HTTP para poder ser testada.

require_once __DIR__ . '/Sns.php';
require_once __DIR__ . '/Eventos.php';
require_once __DIR__ . '/Armazenamento.php';

final class Receptor
{
    /** @var callable */ private $verificar;
    /** @var callable */ private $confirmar;
    /** @var callable */ private $aoReceber;

    /**
     * $cfg: token, topicos (array), confirmarAutomaticamente (bool), aceitarSemAssinatura (bool)
     * $deps: verificar(array):array, confirmar(array):array, aoReceber(array):void
     */
    public function __construct(private array $cfg, private Armazenamento $dados, array $deps = [])
    {
        $cache = $dados->pasta() . '/cache';
        $this->verificar = $deps['verificar'] ?? fn(array $m) => Sns::verificar($m, Sns::baixadorComCache($cache));
        $this->confirmar = $deps['confirmar'] ?? fn(array $m) => Sns::confirmarInscricao($m, [Sns::class, 'httpGet']);
        // Ponto para plugar o destino dos dados (planilha, banco, CRM...).
        $this->aoReceber = $deps['aoReceber'] ?? function (array $ev): void {};
    }

    /** @return array{0:int,1:array} [status HTTP, corpo] */
    public function processar(string $corpo, ?string $tipoNoCabecalho, ?string $tokenNaUrl): array
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

        // 4) Evento do Trinks
        try {
            $ev = Eventos::ler($m);
        } catch (Throwable $e) {
            // Erro permanente: 200 para o SNS não reenviar sem fim. A mensagem crua já foi gravada.
            $this->dados->log('erro', 'evento ilegível', ['erro' => $e->getMessage(), 'messageId' => $m['MessageId'] ?? null]);
            return [200, ['ok' => false, 'erro' => 'evento ilegível']];
        }

        if ($this->dados->jaVisto($ev['idUnico'])) {
            return [200, ['ok' => true, 'duplicado' => true]];
        }

        try {
            $this->dados->salvarEvento($ev);
            ($this->aoReceber)($ev);
            $this->dados->marcarVisto($ev['idUnico']);
        } catch (Throwable $e) {
            // Erro temporário: 500 faz o SNS tentar de novo.
            $this->dados->log('erro', 'falha ao processar evento', ['erro' => $e->getMessage(), 'id' => $ev['idUnico']]);
            return [500, ['erro' => 'falha ao processar']];
        }

        $this->dados->log('info', 'evento recebido', ['evento' => $ev['evento'], 'acao' => $ev['acao'], 'id' => $ev['idUnico']]);
        return [200, ['ok' => true, 'evento' => $ev['evento'], 'acao' => $ev['acao']]];
    }
}
