<?php
// Liga o receptor ao banco sem nunca travar o webhook:
// - se o banco falhar (fora do ar, senha errada...), o evento vai para <dados>/banco-pendentes.jsonl
//   e é gravado depois, na próxima vez que o banco responder;
// - importar() copia para o banco todos os eventos já guardados em arquivo (inclusive os de antes do banco existir).

require_once __DIR__ . '/Banco.php';
require_once __DIR__ . '/Armazenamento.php';

final class GravadorBanco
{
    private ?Banco $banco = null;

    /** @param callable():Banco $conectar */
    public function __construct(private $conectar, private Armazenamento $dados) {}

    public static function daConfig(array $cfg, Armazenamento $dados): ?self
    {
        if (!Banco::configurado($cfg)) return null;
        return new self(fn() => new Banco($cfg['banco'], $dados->pasta()), $dados);
    }

    private function banco(): Banco
    {
        return $this->banco ??= ($this->conectar)();
    }

    /** Grava o evento. Nunca lança erro: em caso de falha, guarda na fila de pendentes. */
    public function gravar(array $ev): bool
    {
        try {
            $this->banco()->gravar($ev);
        } catch (Throwable $e) {
            $this->dados->log('erro', 'banco: falha ao gravar, evento guardado para tentar de novo',
                ['erro' => self::resumirErro($e), 'id' => $ev['idUnico'] ?? null]);
            try { $this->dados->adicionarPendenteBanco($ev); } catch (Throwable $e2) {}
            return false;
        }
        try { $this->reprocessarPendentes(20); } catch (Throwable $e) {}
        return true;
    }

    /** Tenta gravar até $limite eventos pendentes. Devolve quantos foram gravados. */
    public function reprocessarPendentes(int $limite = 1000): int
    {
        $gravados = 0;
        $this->dados->consumirPendentesBanco(function (array $ev) use (&$gravados, &$limite): bool {
            if ($limite-- <= 0) return false;
            $this->banco()->gravar($ev);
            $gravados++;
            return true;
        });
        if ($gravados) $this->dados->log('info', 'banco: pendentes gravados', ['quantidade' => $gravados]);
        return $gravados;
    }

    /** Copia para o banco todos os eventos guardados em <dados>/eventos. Pode ser repetido sem duplicar. */
    public function importar(): array
    {
        $r = ['arquivos' => 0, 'eventos' => 0, 'erros' => 0];
        foreach ($this->dados->arquivosDeEventos() as $arq) {
            $r['arquivos']++;
            $h = fopen($arq, 'r');
            while (($l = fgets($h)) !== false) {
                $ev = json_decode($l, true);
                if (!is_array($ev) || !isset($ev['payload'], $ev['idUnico'])) continue;
                try { $this->banco()->gravar($ev); $r['eventos']++; }
                catch (Throwable $e) {
                    $r['erros']++;
                    $this->dados->log('erro', 'banco: falha ao importar evento', ['erro' => self::resumirErro($e), 'id' => $ev['idUnico']]);
                }
            }
            fclose($h);
        }
        $r['pendentesGravados'] = $this->reprocessarPendentes();
        return $r;
    }

    /** Mensagem de erro sem dados sensíveis (o PDO às vezes inclui o usuário do banco). */
    public static function resumirErro(Throwable $e): string
    {
        $m = $e->getMessage();
        if ($e instanceof PDOException) {
            $m = preg_replace("/'[^']*'@'[^']*'/", "'***'@'***'", $m);
        }
        return mb_substr($m, 0, 300);
    }
}
