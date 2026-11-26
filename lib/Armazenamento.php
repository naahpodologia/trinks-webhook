<?php
// Gravação em arquivos JSON Lines (um evento por linha).
// <dados>/eventos/AAAA-MM-DD/<evento>.jsonl  -> eventos organizados
// <dados>/sns/AAAA-MM-DD.jsonl               -> toda mensagem SNS recebida (auditoria)
// <dados>/vistos.txt                         -> ids já processados (o SNS pode reenviar)
// <dados>/banco-pendentes.jsonl              -> eventos que o banco não aceitou na hora (gravados depois)

final class Armazenamento
{
    public function __construct(private string $pasta)
    {
        if (!is_dir($pasta) && !mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            throw new RuntimeException('não consegui criar a pasta de dados');
        }
    }

    public function pasta(): string { return $this->pasta; }

    private function anexar(string $arquivo, array $dado): void
    {
        $dir = dirname($arquivo);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $linha = json_encode($dado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($arquivo, $linha, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('falha ao gravar ' . basename($arquivo));
        }
    }

    private function dia(): string { return gmdate('Y-m-d'); }

    public function jaVisto(string $id): bool { return $this->contem('vistos.txt', $id); }
    public function jaRepassado(string $chave): bool { return $this->contem('repassados.txt', $chave); }
    public function marcarRepassado(string $chave): void
    {
        file_put_contents($this->pasta . '/repassados.txt', $chave . "\n", FILE_APPEND | LOCK_EX);
    }

    private function contem(string $nome, string $id): bool
    {
        $arq = $this->pasta . '/' . $nome;
        if ($id === '' || !is_file($arq)) return false;
        $h = fopen($arq, 'r');
        while (($l = fgets($h)) !== false) {
            if (rtrim($l, "\n") === $id) { fclose($h); return true; }
        }
        fclose($h);
        return false;
    }

    public function marcarVisto(string $id): void
    {
        file_put_contents($this->pasta . '/vistos.txt', $id . "\n", FILE_APPEND | LOCK_EX);
    }

    public function salvarBruto(array $m): void
    {
        $this->anexar($this->pasta . '/sns/' . $this->dia() . '.jsonl', ['recebidoEm' => gmdate('c')] + $m);
    }

    public function salvarEvento(array $ev): void
    {
        $this->anexar($this->pasta . '/eventos/' . $this->dia() . '/' . $ev['evento'] . '.jsonl', $ev);
    }

    public function salvarInscricao(array $info): void
    {
        $this->anexar($this->pasta . '/inscricoes.jsonl', ['em' => gmdate('c')] + $info);
    }

    public function log(string $nivel, string $msg, array $extra = []): void
    {
        $this->anexar($this->pasta . '/log/' . $this->dia() . '.jsonl', ['em' => gmdate('c'), 'nivel' => $nivel, 'msg' => $msg] + $extra);
    }

    // ---- fila de eventos à espera do banco

    public function adicionarPendenteBanco(array $ev): void
    {
        $this->anexar($this->pasta . '/banco-pendentes.jsonl', $ev);
    }

    /**
     * Passa cada pendente para $gravar. Os que forem gravados saem da fila; se $gravar lançar erro
     * ou devolver false, o restante continua na fila.
     * @param callable(array):bool $gravar
     */
    public function consumirPendentesBanco(callable $gravar): void
    {
        $arq = $this->pasta . '/banco-pendentes.jsonl';
        if (!is_file($arq) || filesize($arq) === 0) return;
        $h = fopen($arq, 'c+');
        if (!$h || !flock($h, LOCK_EX | LOCK_NB)) { if ($h) fclose($h); return; } // outro processo já está cuidando
        $linhas = [];
        while (($l = fgets($h)) !== false) if (trim($l) !== '') $linhas[] = $l;
        $resto = [];
        $parar = false;
        foreach ($linhas as $l) {
            if (!$parar) {
                $ev = json_decode($l, true);
                if (!is_array($ev)) continue; // linha corrompida: descarta
                try { if ($gravar($ev)) continue; } catch (Throwable $e) {}
                $parar = true;
            }
            $resto[] = $l;
        }
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, implode('', $resto));
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
    }

    public function quantidadePendentesBanco(): int
    {
        $arq = $this->pasta . '/banco-pendentes.jsonl';
        return is_file($arq) ? count(array_filter(file($arq), fn($l) => trim($l) !== '')) : 0;
    }

    /** Arquivos <dados>/eventos/AAAA-MM-DD/*.jsonl em ordem de data. */
    public function arquivosDeEventos(): array
    {
        $a = glob($this->pasta . '/eventos/*/*.jsonl') ?: [];
        sort($a);
        return $a;
    }
}
