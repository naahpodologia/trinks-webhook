<?php
// Tipos de evento e ações do webhook do Trinks (https://trinks.readme.io/reference/webhook)

final class Eventos
{
    public const TIPOS = [
        1 => ['fechamento-de-conta', 'Fechamento de Conta', 'financeiro'],
        2 => ['estorno-de-conta', 'Estorno de Conta', 'financeiro'],
        3 => ['inclusao-de-cliente', 'Inclusão de Cliente', 'clientes'],
        4 => ['alteracao-de-cliente', 'Alteração de Cliente', 'clientes'],
        5 => ['inclusao-de-profissional', 'Inclusão de Profissional', 'profissionais'],
        6 => ['alteracao-de-profissional', 'Alteração de Profissional', 'profissionais'],
        7 => ['inativacao-de-profissional', 'Inativação de Profissional', 'profissionais'],
        8 => ['cadastro-de-estabelecimento', 'Cadastro de Estabelecimento', 'estabelecimentos'],
        9 => ['exclusao-de-estabelecimento', 'Exclusão de Estabelecimento', 'estabelecimentos'],
        10 => ['alteracao-de-estabelecimento', 'Alteração de Estabelecimento', 'estabelecimentos'],
        11 => ['inclusao-de-agendamento', 'Inclusão de Agendamento', 'agendamentos'],
        12 => ['alteracao-de-agendamento', 'Alteração de Agendamento', 'agendamentos'],
        13 => ['exclusao-de-agendamento', 'Exclusão de Agendamento', 'agendamentos'],
        14 => ['movimentacao-de-estoque', 'Movimentação de Estoque', 'estoque'],
    ];
    public const ACOES = [1 => 'inclusao', 2 => 'alteracao', 3 => 'exclusao', 9 => 'estorno'];
    public const TIPOS_ITEM = [1 => 'servico', 2 => 'produto', 3 => 'pacote', 4 => 'vale-presente', 5 => 'clube-de-assinatura', 9 => 'outros'];

    /** "13,50", "1.234,56", "10" ou 80 -> número. Vazio -> null. */
    public static function numero($v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v)) return (float)$v;
        $s = trim((string)$v);
        if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
        return is_numeric($s) ? (float)$s : null;
    }

    public static function ler(array $sns): array
    {
        $p = is_string($sns['Message'] ?? null) ? json_decode($sns['Message'], true) : null;
        if (!is_array($p)) throw new InvalidArgumentException('Message não é um JSON válido');

        $tipo = (int)($p['TipoDeEvento'] ?? 0);
        $acao = (int)($p['Action'] ?? 0);
        $info = self::TIPOS[$tipo] ?? ['desconhecido-' . ($p['TipoDeEvento'] ?? ''), 'Evento desconhecido', 'desconhecido'];

        return [
            // IdEvento existe no evento de estoque; nos demais usamos o MessageId do SNS
            'idUnico' => (string)($p['IdEvento'] ?? $sns['MessageId'] ?? ''),
            'snsMessageId' => $sns['MessageId'] ?? null,
            'recebidoEm' => gmdate('c'),
            'snsTimestamp' => $sns['Timestamp'] ?? null,
            'subject' => $sns['Subject'] ?? null,
            'tipoDeEvento' => $tipo,
            'evento' => $info[0],
            'eventoNome' => $info[1],
            'grupo' => $info[2],
            'action' => $acao,
            'acao' => self::ACOES[$acao] ?? 'desconhecida-' . ($p['Action'] ?? ''),
            'idDoEstabelecimento' => $p['IdDoEstabelecimento'] ?? null,
            'conhecido' => isset(self::TIPOS[$tipo]),
            'resumo' => self::resumo($tipo, $p),
            'payload' => $p, // dado bruto, sempre preservado
        ];
    }

    private static function resumo(int $tipo, array $p): array
    {
        $n = fn($k) => self::numero($p[$k] ?? null);
        switch ($tipo) {
            case 1:
            case 2:
                return [
                    'idDaTransacao' => $p['IdDaTransacao'] ?? null,
                    'cliente' => $p['NomeDoCliente'] ?? null,
                    'idDoClienteNoEstabelecimento' => $p['IdDoClienteNoEstabelecimento'] ?? null,
                    'data' => $p['DataDoFechamento'] ?? $p['DataDoEstorno'] ?? null,
                    'valorDaCompra' => $n('ValorDaCompra'),
                    'totalServicos' => $n('TotalServicos'),
                    'totalProdutos' => $n('TotalProdutos'),
                    'totalDescontos' => $n('TotalDescontos'),
                    'totalRecebido' => $n('TotalRecebido'),
                    'profissionalQueFechou' => $p['NomeDoProfissionalQueFechouConta'] ?? null,
                    'itens' => array_map(fn($i) => [
                        'nome' => $i['Nome'] ?? null,
                        'tipo' => self::TIPOS_ITEM[$i['TipoItem'] ?? 0] ?? ($i['TipoItem'] ?? null),
                        'quantidade' => self::numero($i['Quantidade'] ?? null),
                        'total' => self::numero($i['Total'] ?? null),
                        'idDoAgendamento' => $i['IdDoAgendamento'] ?? null,
                    ], is_array($p['Itens'] ?? null) ? $p['Itens'] : []),
                    'formasPagamento' => array_map(fn($f) => [
                        'forma' => $f['FormaPagamento'] ?? null,
                        'valor' => self::numero($f['Valor'] ?? null),
                    ], is_array($p['FormasPagamento'] ?? null) ? $p['FormasPagamento'] : []),
                ];
            case 3:
            case 4:
                return [
                    'idDoCliente' => $p['IdDoCliente'] ?? null,
                    'idDoClienteNoEstabelecimento' => $p['IdDoClienteNoEstabelecimento'] ?? null,
                    'nome' => $p['Nome'] ?? null,
                    'telefone' => $p['Telefone'] ?? null,
                    'email' => $p['Email'] ?? null,
                    'origem' => $p['OrigemDoCliente'] ?? null,
                    'comoNosConheceu' => $p['ComoNosConheceu'] ?? null,
                    'dataDeInclusao' => $p['DataDeInclusao'] ?? null,
                ];
            case 14:
                return [
                    'origem' => $p['Origem'] ?? null,
                    'dataHora' => $p['DataHoraEventoGerado'] ?? null,
                    'itens' => array_map(fn($i) => [
                        'produto' => $i['NomeDoProduto'] ?? null,
                        'tipo' => $i['DescricaoTipoDeMovimentacao'] ?? null,
                        'quantidade' => self::numero($i['Quantidade'] ?? null),
                        'estoqueApos' => self::numero($i['EstoqueDoProdutoAposAOperacao'] ?? null),
                        'custoTotal' => self::numero($i['CustoTotalDaMovimentacao'] ?? null),
                    ], is_array($p['Itens'] ?? null) ? $p['Itens'] : []),
                ];
            default:
                // Agendamentos, profissionais e estabelecimentos: campos de identificação mais prováveis.
                $chaves = ['IdDoAgendamento', 'IdDoCliente', 'IdDoClienteNoEstabelecimento', 'NomeDoCliente',
                    'IdDoProfissional', 'IdDoProfissionalNoEstabelecimento', 'Nome', 'NomeDoProfissional',
                    'DataHoraInicio', 'DataHoraFim', 'Data', 'Status', 'NomeDoServico'];
                return array_intersect_key($p, array_flip($chaves));
        }
    }
}
