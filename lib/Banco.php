<?php
// Gravação dos eventos do Trinks em MySQL, de forma organizada para consultas e relatórios.
//
// Tabelas (criadas sozinhas na primeira conexão):
//   trinks_eventos             todo evento recebido, com o JSON original completo (nunca se perde nada)
//   trinks_clientes            um registro por cliente, sempre com os dados mais recentes
//   trinks_vendas              um registro por conta fechada (estornos marcam a venda como estornada)
//   trinks_venda_itens         serviços e produtos de cada venda
//   trinks_venda_pagamentos    formas de pagamento de cada venda
//   trinks_agendamentos        um registro por agendamento, com o status mais recente
//   trinks_profissionais       um registro por profissional
//   trinks_estoque_movimentacoes  cada entrada/saída de produto
//
// Datas do Trinks são gravadas como vêm (horário de Brasília). recebido_em também fica em horário de Brasília.
// Gravar duas vezes o mesmo evento não duplica nada (chaves únicas + "INSERT ... ON DUPLICATE KEY UPDATE").

require_once __DIR__ . '/Eventos.php';

final class Banco
{
    public const VERSAO_ESQUEMA = 1;

    private PDO $pdo;
    private string $dsn;

    public function __construct(array $cfg, private ?string $pastaDados = null)
    {
        $host = $cfg['host'] ?? 'localhost';
        $porta = (int)($cfg['porta'] ?? 3306);
        $dsn = $this->dsn = "mysql:host=$host;port=$porta;dbname={$cfg['nome']};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $cfg['usuario'] ?? '', $cfg['senha'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '-03:00'");
        $this->criarTabelas();
    }

    public static function configurado(array $cfg): bool
    {
        $b = $cfg['banco'] ?? null;
        return is_array($b) && !empty($b['nome']) && !empty($b['usuario']);
    }

    public function pdo(): PDO { return $this->pdo; }

    // ------------------------------------------------------------------ esquema

    private function criarTabelas(): void
    {
        // Evita rodar os CREATE TABLE a cada requisição.
        $marca = $this->pastaDados ? $this->pastaDados . '/banco-esquema-v' . self::VERSAO_ESQUEMA . '-'
            . substr(hash('sha256', $this->dsn), 0, 8) . '.ok' : null;
        if ($marca && is_file($marca)) return;
        foreach (self::esquema() as $sql) $this->pdo->exec($sql);
        if ($marca) @file_put_contents($marca, gmdate('c'));
    }

    public static function esquema(): array
    {
        $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS trinks_eventos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                id_unico VARCHAR(100) NOT NULL,
                sns_message_id VARCHAR(100) NULL,
                recebido_em DATETIME NOT NULL,
                tipo_de_evento SMALLINT NOT NULL,
                evento VARCHAR(60) NOT NULL COMMENT 'ex.: fechamento-de-conta',
                evento_nome VARCHAR(80) NOT NULL COMMENT 'ex.: Fechamento de Conta',
                grupo VARCHAR(30) NOT NULL COMMENT 'financeiro, clientes, agendamentos, estoque...',
                acao VARCHAR(30) NOT NULL COMMENT 'inclusao, alteracao, exclusao, estorno',
                id_estabelecimento BIGINT NULL,
                payload JSON NOT NULL COMMENT 'evento original completo do Trinks',
                UNIQUE KEY uk_id_unico (id_unico),
                KEY ix_evento_data (evento, recebido_em),
                KEY ix_recebido (recebido_em)
            ) $t",
            "CREATE TABLE IF NOT EXISTS trinks_clientes (
                id_cliente_estabelecimento BIGINT NOT NULL PRIMARY KEY COMMENT 'código do cliente no Trinks da Naah',
                id_cliente BIGINT NULL,
                nome VARCHAR(200) NULL,
                cpf VARCHAR(20) NULL,
                email VARCHAR(200) NULL,
                telefone VARCHAR(60) NULL COMMENT 'primeiro telefone',
                telefones VARCHAR(255) NULL COMMENT 'todos, separados por vírgula',
                data_nascimento DATE NULL,
                sexo VARCHAR(5) NULL,
                bairro VARCHAR(100) NULL,
                cidade VARCHAR(100) NULL,
                uf VARCHAR(5) NULL,
                como_nos_conheceu VARCHAR(100) NULL,
                origem VARCHAR(100) NULL,
                data_cadastro DATETIME NULL COMMENT 'data de inclusão no Trinks',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY ix_nome (nome),
                KEY ix_cadastro (data_cadastro)
            ) $t",
            "CREATE TABLE IF NOT EXISTS trinks_vendas (
                id_transacao BIGINT NOT NULL PRIMARY KEY,
                data_fechamento DATETIME NULL,
                id_cliente_estabelecimento BIGINT NULL,
                cliente_nome VARCHAR(200) NULL,
                valor_compra DECIMAL(12,2) NULL,
                total_servicos DECIMAL(12,2) NULL,
                total_produtos DECIMAL(12,2) NULL,
                total_pacotes DECIMAL(12,2) NULL,
                total_descontos DECIMAL(12,2) NULL,
                total_a_pagar DECIMAL(12,2) NULL,
                total_recebido DECIMAL(12,2) NULL,
                total_credito DECIMAL(12,2) NULL,
                total_debito DECIMAL(12,2) NULL,
                total_dinheiro DECIMAL(12,2) NULL,
                total_outros DECIMAL(12,2) NULL,
                gorjeta DECIMAL(12,2) NULL,
                troco DECIMAL(12,2) NULL,
                id_profissional_que_fechou BIGINT NULL,
                profissional_que_fechou VARCHAR(200) NULL,
                estornada TINYINT(1) NOT NULL DEFAULT 0,
                data_estorno DATETIME NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY ix_data (data_fechamento),
                KEY ix_cliente (id_cliente_estabelecimento)
            ) $t",
            "CREATE TABLE IF NOT EXISTS trinks_venda_itens (
                id_transacao BIGINT NOT NULL,
                ordem SMALLINT NOT NULL,
                tipo VARCHAR(30) NULL COMMENT 'servico, produto, pacote...',
                nome VARCHAR(200) NULL,
                quantidade DECIMAL(12,3) NULL,
                valor_unitario DECIMAL(12,2) NULL,
                desconto DECIMAL(12,2) NULL,
                motivo_desconto VARCHAR(200) NULL,
                total DECIMAL(12,2) NULL,
                ean VARCHAR(50) NULL,
                id_agendamento BIGINT NULL,
                PRIMARY KEY (id_transacao, ordem),
                KEY ix_nome (nome),
                KEY ix_agendamento (id_agendamento)
            ) $t",
            "CREATE TABLE IF NOT EXISTS trinks_venda_pagamentos (
                id_transacao BIGINT NOT NULL,
                ordem SMALLINT NOT NULL,
                forma_pagamento VARCHAR(100) NULL,
                valor DECIMAL(12,2) NULL,
                PRIMARY KEY (id_transacao, ordem)
            ) $t",
            "CREATE TABLE IF NOT EXISTS trinks_agendamentos (
                id_agendamento BIGINT NOT NULL PRIMARY KEY,
                id_cliente_estabelecimento BIGINT NULL,
                cliente_nome VARCHAR(200) NULL,
                id_profissional_estabelecimento BIGINT NULL,
                profissional_nome VARCHAR(200) NULL,
                servico VARCHAR(200) NULL,
                inicio DATETIME NULL,
                fim DATETIME NULL,
                status VARCHAR(60) NULL,
                excluido TINYINT(1) NOT NULL DEFAULT 0,
                ultimo_payload JSON NULL COMMENT 'último evento recebido deste agendamento',
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY ix_inicio (inicio),
                KEY ix_cliente (id_cliente_estabelecimento)
            ) $t",
            "CREATE TABLE IF NOT EXISTS trinks_profissionais (
                id_profissional_estabelecimento BIGINT NOT NULL PRIMARY KEY,
                id_profissional BIGINT NULL,
                nome VARCHAR(200) NULL,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                ultimo_payload JSON NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) $t",
            "CREATE TABLE IF NOT EXISTS trinks_estoque_movimentacoes (
                id_movimentacao BIGINT NOT NULL PRIMARY KEY,
                id_evento VARCHAR(100) NULL,
                data_movimentacao DATETIME NULL,
                origem VARCHAR(100) NULL COMMENT 'ex.: BaixaAutomaticaNoAgendamento',
                tipo VARCHAR(100) NULL COMMENT 'ex.: Saída automática',
                id_produto BIGINT NULL,
                produto VARCHAR(200) NULL,
                sku VARCHAR(60) NULL,
                codigo_de_barras VARCHAR(60) NULL,
                unidade VARCHAR(20) NULL,
                quantidade DECIMAL(12,3) NULL COMMENT 'negativa = saída',
                custo_medio DECIMAL(12,2) NULL,
                custo_total DECIMAL(12,2) NULL,
                estoque_apos DECIMAL(12,3) NULL,
                id_profissional_estabelecimento BIGINT NULL,
                profissional_nome VARCHAR(200) NULL,
                id_cliente_estabelecimento BIGINT NULL,
                id_agendamento BIGINT NULL,
                observacao VARCHAR(255) NULL,
                KEY ix_data (data_movimentacao),
                KEY ix_produto (id_produto)
            ) $t",
        ];
    }

    // ------------------------------------------------------------------ gravação

    /** Grava um evento (formato de Eventos::ler) em tudo que for preciso, numa única transação. */
    public function gravar(array $ev): void
    {
        $p = $ev['payload'];
        $this->pdo->beginTransaction();
        try {
            $this->upsert('trinks_eventos', [
                'id_unico' => $ev['idUnico'],
                'sns_message_id' => $ev['snsMessageId'],
                'recebido_em' => self::horaBrasilia($ev['recebidoEm'] ?? null),
                'tipo_de_evento' => $ev['tipoDeEvento'],
                'evento' => $ev['evento'],
                'evento_nome' => $ev['eventoNome'],
                'grupo' => $ev['grupo'],
                'acao' => $ev['acao'],
                'id_estabelecimento' => self::inteiro($ev['idDoEstabelecimento']),
                'payload' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], ['id_unico']);

            switch ($ev['tipoDeEvento']) {
                case 1: $this->venda($p); break;
                case 2: $this->estorno($p); break;
                case 3: case 4: $this->cliente($p); break;
                case 5: case 6: case 7: $this->profissional($p, $ev['tipoDeEvento'] !== 7); break;
                case 11: case 12: case 13: $this->agendamento($p, $ev['tipoDeEvento'] === 13); break;
                case 14: $this->estoque($p); break;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function venda(array $p): void
    {
        $id = self::inteiro($p['IdDaTransacao'] ?? null);
        if ($id === null) return;
        $n = fn($k) => Eventos::numero($p[$k] ?? null);
        $this->upsert('trinks_vendas', [
            'id_transacao' => $id,
            'data_fechamento' => self::data($p['DataDoFechamento'] ?? null),
            'id_cliente_estabelecimento' => self::inteiro($p['IdDoClienteNoEstabelecimento'] ?? null),
            'cliente_nome' => self::texto($p['NomeDoCliente'] ?? null),
            'valor_compra' => $n('ValorDaCompra'),
            'total_servicos' => $n('TotalServicos'),
            'total_produtos' => $n('TotalProdutos'),
            'total_pacotes' => $n('TotalPacotes'),
            'total_descontos' => $n('TotalDescontos'),
            'total_a_pagar' => $n('TotalAPagar'),
            'total_recebido' => $n('TotalRecebido'),
            'total_credito' => $n('TotalCredito'),
            'total_debito' => $n('TotalDebito'),
            'total_dinheiro' => $n('TotalDinheiro'),
            'total_outros' => $n('TotalOutros'),
            'gorjeta' => $n('Gorjeta'),
            'troco' => $n('Troco'),
            'id_profissional_que_fechou' => self::inteiro($p['IdDoProfissionalQueFechouConta'] ?? null),
            'profissional_que_fechou' => self::texto($p['NomeDoProfissionalQueFechouConta'] ?? null),
        ], ['id_transacao']);

        $this->pdo->prepare('DELETE FROM trinks_venda_itens WHERE id_transacao = ?')->execute([$id]);
        foreach (array_values(is_array($p['Itens'] ?? null) ? $p['Itens'] : []) as $i => $it) {
            $this->upsert('trinks_venda_itens', [
                'id_transacao' => $id,
                'ordem' => $i + 1,
                'tipo' => Eventos::TIPOS_ITEM[$it['TipoItem'] ?? 0] ?? self::texto($it['TipoItem'] ?? null),
                'nome' => self::texto($it['Nome'] ?? null),
                'quantidade' => Eventos::numero($it['Quantidade'] ?? null),
                'valor_unitario' => Eventos::numero($it['ValorUnitario'] ?? null),
                'desconto' => Eventos::numero($it['DescontoTotal'] ?? null),
                'motivo_desconto' => self::texto($it['MotivoDesconto'] ?? null),
                'total' => Eventos::numero($it['Total'] ?? null),
                'ean' => self::texto($it['EAN'] ?? null),
                'id_agendamento' => self::inteiro($it['IdDoAgendamento'] ?? null),
            ], ['id_transacao', 'ordem']);
        }
        $this->pdo->prepare('DELETE FROM trinks_venda_pagamentos WHERE id_transacao = ?')->execute([$id]);
        foreach (array_values(is_array($p['FormasPagamento'] ?? null) ? $p['FormasPagamento'] : []) as $i => $f) {
            $this->upsert('trinks_venda_pagamentos', [
                'id_transacao' => $id,
                'ordem' => $i + 1,
                'forma_pagamento' => self::texto($f['FormaPagamento'] ?? null),
                'valor' => Eventos::numero($f['Valor'] ?? null),
            ], ['id_transacao', 'ordem']);
        }

        // O fechamento traz os dados do cliente: aproveita para manter o cadastro em dia.
        if (isset($p['IdDoClienteNoEstabelecimento'])) {
            $this->cliente([
                'IdDoClienteNoEstabelecimento' => $p['IdDoClienteNoEstabelecimento'],
                'IdDoCliente' => $p['IdDoCliente'] ?? null,
                'Nome' => $p['NomeDoCliente'] ?? null,
                'CPF' => $p['CPFDoCliente'] ?? null,
                'Email' => $p['EmailDoCliente'] ?? null,
                'Telefone' => $p['TelefoneDoCliente'] ?? null,
                'DataDeNascimento' => $p['DataDeNascimentoDoCliente'] ?? null,
                'Sexo' => $p['SexoDoCliente'] ?? null,
                'Endereco' => $p['EnderecoDoCliente'] ?? null,
                'DataDeInclusao' => $p['DataDeInclusaoDoCliente'] ?? null,
            ]);
        }
    }

    private function estorno(array $p): void
    {
        $id = self::inteiro($p['IdDaTransacao'] ?? null);
        if ($id === null) return;
        // Se a venda ainda não estiver no banco, cria com o que o estorno trouxer.
        $this->upsert('trinks_vendas', [
            'id_transacao' => $id,
            'id_cliente_estabelecimento' => self::inteiro($p['IdDoClienteNoEstabelecimento'] ?? null),
            'cliente_nome' => self::texto($p['NomeDoCliente'] ?? null),
            'valor_compra' => Eventos::numero($p['ValorDaCompra'] ?? null),
            'estornada' => 1,
            'data_estorno' => self::data($p['DataDoEstorno'] ?? null) ?? self::horaBrasilia(null),
        ], ['id_transacao']);
    }

    private function cliente(array $p): void
    {
        $id = self::inteiro($p['IdDoClienteNoEstabelecimento'] ?? null);
        if ($id === null) return;
        $tel = $p['Telefone'] ?? null;
        $tels = array_values(array_filter(array_map('strval', is_array($tel) ? $tel : [$tel]), fn($t) => trim($t) !== ''));
        $end = is_array($p['Endereco'] ?? null) ? $p['Endereco'] : [];
        $this->upsert('trinks_clientes', [
            'id_cliente_estabelecimento' => $id,
            'id_cliente' => self::inteiro($p['IdDoCliente'] ?? null),
            'nome' => self::texto($p['Nome'] ?? null),
            'cpf' => self::texto($p['CPF'] ?? null),
            'email' => self::texto($p['Email'] ?? null),
            'telefone' => $tels[0] ?? null,
            'telefones' => $tels ? mb_substr(implode(', ', $tels), 0, 255) : null,
            'data_nascimento' => self::data($p['DataDeNascimento'] ?? null, true),
            'sexo' => self::texto($p['Sexo'] ?? null),
            'bairro' => self::texto($end['Bairro'] ?? null),
            'cidade' => self::texto($end['Cidade'] ?? null),
            'uf' => self::texto($end['UF'] ?? null),
            'como_nos_conheceu' => self::texto($p['ComoNosConheceu'] ?? null),
            'origem' => self::texto($p['OrigemDoCliente'] ?? null),
            'data_cadastro' => self::data($p['DataDeInclusao'] ?? null),
        ], ['id_cliente_estabelecimento']);
    }

    private function profissional(array $p, bool $ativo): void
    {
        $id = self::inteiro($p['IdDoProfissionalNoEstabelecimento'] ?? null);
        if ($id === null) return;
        $this->upsert('trinks_profissionais', [
            'id_profissional_estabelecimento' => $id,
            'id_profissional' => self::inteiro($p['IdDoProfissional'] ?? null),
            'nome' => self::texto($p['Nome'] ?? $p['NomeDoProfissional'] ?? null),
            'ativo' => $ativo ? 1 : 0,
            'ultimo_payload' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], ['id_profissional_estabelecimento'], ['ativo']);
    }

    private function agendamento(array $p, bool $excluido): void
    {
        $id = self::inteiro($p['IdDoAgendamento'] ?? null);
        if ($id === null) return;
        // Os nomes de campo dos eventos de agendamento ainda não foram confirmados com um exemplo real;
        // o JSON completo fica em ultimo_payload (e em trinks_eventos) para ajustar depois sem perder nada.
        $this->upsert('trinks_agendamentos', [
            'id_agendamento' => $id,
            'id_cliente_estabelecimento' => self::inteiro($p['IdDoClienteNoEstabelecimento'] ?? null),
            'cliente_nome' => self::texto($p['NomeDoCliente'] ?? null),
            'id_profissional_estabelecimento' => self::inteiro($p['IdDoProfissionalNoEstabelecimento'] ?? null),
            'profissional_nome' => self::texto($p['NomeDoProfissional'] ?? null),
            'servico' => self::texto($p['NomeDoServico'] ?? $p['NomeDoServicoNoEstabelecimento'] ?? null),
            'inicio' => self::data($p['DataHoraInicio'] ?? $p['Data'] ?? null),
            'fim' => self::data($p['DataHoraFim'] ?? null),
            'status' => self::texto($p['Status'] ?? null),
            'excluido' => $excluido ? 1 : 0,
            'ultimo_payload' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], ['id_agendamento'], ['excluido']);
    }

    private function estoque(array $p): void
    {
        $ag = is_array($p['Agendamento'] ?? null) ? $p['Agendamento'] : [];
        foreach (is_array($p['Itens'] ?? null) ? $p['Itens'] : [] as $it) {
            $id = self::inteiro($it['IdDaMovimentacao'] ?? null);
            if ($id === null) continue;
            $prof = is_array($it['ProfissionalResponsavel'] ?? null) ? $it['ProfissionalResponsavel'] : [];
            $this->upsert('trinks_estoque_movimentacoes', [
                'id_movimentacao' => $id,
                'id_evento' => self::texto($p['IdEvento'] ?? null),
                'data_movimentacao' => self::data($it['DataMovimentacao'] ?? $p['DataHoraEventoGerado'] ?? null),
                'origem' => self::texto($p['Origem'] ?? null),
                'tipo' => self::texto($it['DescricaoTipoDeMovimentacao'] ?? null),
                'id_produto' => self::inteiro($it['IdDoProduto'] ?? null),
                'produto' => self::texto($it['NomeDoProduto'] ?? null),
                'sku' => self::texto($it['SKU'] ?? null),
                'codigo_de_barras' => self::texto($it['CodigoDeBarras'] ?? null),
                'unidade' => self::texto($it['UnidadeDeMedida'] ?? null),
                'quantidade' => Eventos::numero($it['Quantidade'] ?? null),
                'custo_medio' => Eventos::numero($it['CustoMedio'] ?? null),
                'custo_total' => Eventos::numero($it['CustoTotalDaMovimentacao'] ?? null),
                'estoque_apos' => Eventos::numero($it['EstoqueDoProdutoAposAOperacao'] ?? null),
                'id_profissional_estabelecimento' => self::inteiro($prof['IdDoProfissionalNoEstabelecimento'] ?? null),
                'profissional_nome' => self::texto($prof['Nome'] ?? null),
                'id_cliente_estabelecimento' => self::inteiro($it['IdDoClienteNoEstabelecimento'] ?? $ag['IdDoClienteNoEstabelecimento'] ?? null),
                'id_agendamento' => self::inteiro($ag['IdDoAgendamento'] ?? null),
                'observacao' => self::texto($it['Observacao'] ?? null),
            ], ['id_movimentacao']);
        }
    }

    // ------------------------------------------------------------------ utilitários

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE. Na atualização, um valor vazio (null) não apaga o que já existe,
     * exceto nas colunas de $sempre (ex.: ativo/excluido), que sempre recebem o valor novo.
     */
    private function upsert(string $tabela, array $linha, array $chaves, array $sempre = []): void
    {
        $cols = array_keys($linha);
        $set = [];
        foreach ($cols as $c) {
            if (in_array($c, $chaves, true)) continue;
            $set[] = in_array($c, $sempre, true) ? "$c = VALUES($c)" : "$c = COALESCE(VALUES($c), $c)";
        }
        if (!$set) $set[] = "{$chaves[0]} = {$chaves[0]}";
        $sql = "INSERT INTO $tabela (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $set);
        $this->pdo->prepare($sql)->execute(array_values($linha));
    }

    private static function texto($v): ?string
    {
        if ($v === null || is_array($v)) return null;
        if (is_bool($v)) return $v ? '1' : '0';
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private static function inteiro($v): ?int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && preg_match('/^-?\d+$/', trim($v))) return (int)trim($v);
        if (is_float($v)) return (int)$v;
        return null;
    }

    /** "2026-11-24 10:15:15", "2026-11-24T10:15:15", "24/11/2026 10:15" -> "2026-11-24 10:15:15". */
    private static function data($v, bool $soData = false): ?string
    {
        $s = self::texto($v);
        if ($s === null) return null;
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})(.*)$#', $s, $r)) $s = "$r[3]-$r[2]-$r[1]$r[4]";
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}(?::\d{2})?))?/', $s, $r)) return null;
        if ($r[1] === '0001-01-01') return null;
        if ($soData) return $r[1];
        $hora = $r[2] ?? '00:00:00';
        if (strlen($hora) === 5) $hora .= ':00';
        return "$r[1] $hora";
    }

    /** Data ISO (UTC) -> horário de Brasília. null = agora. */
    private static function horaBrasilia(?string $iso): string
    {
        try { $d = new DateTimeImmutable($iso ?? 'now'); } catch (Throwable $e) { $d = new DateTimeImmutable('now'); }
        return $d->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
    }
}
