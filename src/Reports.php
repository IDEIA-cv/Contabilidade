<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Relatórios contabilísticos: balancete, razão (extrato), diário, balanço e demonstração dos resultados.
 * Base: vista v_movements (só lançamentos validados).
 */
final class Reports
{
    /**
     * Saldos (débito − crédito, em centavos) por conta de movimento.
     *
     * @param bool $withOpening inclui movimentos do diário de abertura e dos meses anteriores a $from
     * @param string[] $excludeKinds tipos de diário a excluir (ex.: 'apuramento')
     */
    public static function leafBalances(Db $db, int $year, int $from = 1, int $to = 12, bool $withOpening = true, array $excludeKinds = []): array
    {
        $where = ['fiscal_year = ?'];
        $p = [$year];
        if ($withOpening) {
            $where[] = '(month <= ? OR journal_kind = \'abertura\')';
            $p[] = $to;
        } else {
            $where[] = 'month BETWEEN ? AND ? AND journal_kind <> \'abertura\'';
            $p[] = $from;
            $p[] = $to;
        }
        foreach ($excludeKinds as $k) {
            $where[] = 'journal_kind <> ?';
            $p[] = $k;
        }
        $out = [];
        foreach ($db->all('SELECT account, SUM(debit_cents) - SUM(credit_cents) AS bal FROM v_movements WHERE ' . implode(' AND ', $where) . ' GROUP BY account', $p) as $r) {
            $out[$r['account']] = (int) $r['bal'];
        }
        return $out;
    }

    /** Balancete com saldo anterior, movimentos do período e saldo final, agregado por todos os níveis. */
    public static function trialBalance(Db $db, int $year, int $from = 1, int $to = 12, ?int $maxLevel = null): array
    {
        $accounts = [];
        foreach ($db->all('SELECT code, name, parent, level FROM accounts') as $a) {
            $accounts[$a['code']] = $a;
        }
        $agg = [];
        $add = static function (string $code, string $col, int $v) use (&$agg, $accounts): void {
            while ($code !== null && $code !== '' && isset($accounts[$code])) {
                $agg[$code] ??= ['opening_debit' => 0, 'opening_credit' => 0, 'debit' => 0, 'credit' => 0];
                $agg[$code][$col] += $v;
                $code = $accounts[$code]['parent'];
            }
        };
        $rows = $db->all(
            "SELECT account,
                    SUM(CASE WHEN journal_kind = 'abertura' OR month < ? THEN debit_cents ELSE 0 END) AS od,
                    SUM(CASE WHEN journal_kind = 'abertura' OR month < ? THEN credit_cents ELSE 0 END) AS oc,
                    SUM(CASE WHEN journal_kind <> 'abertura' AND month BETWEEN ? AND ? THEN debit_cents ELSE 0 END) AS d,
                    SUM(CASE WHEN journal_kind <> 'abertura' AND month BETWEEN ? AND ? THEN credit_cents ELSE 0 END) AS c
             FROM v_movements WHERE fiscal_year = ? AND (month <= ? OR journal_kind = 'abertura') GROUP BY account",
            [$from, $from, $from, $to, $from, $to, $year, $to]
        );
        foreach ($rows as $r) {
            $add($r['account'], 'opening_debit', (int) $r['od']);
            $add($r['account'], 'opening_credit', (int) $r['oc']);
            $add($r['account'], 'debit', (int) $r['d']);
            $add($r['account'], 'credit', (int) $r['c']);
        }
        ksort($agg, SORT_STRING);
        $out = [];
        $totals = ['opening_debit' => 0, 'opening_credit' => 0, 'debit' => 0, 'credit' => 0, 'balance_debit' => 0, 'balance_credit' => 0];
        foreach ($agg as $code => $v) {
            $code = (string) $code;
            $a = $accounts[$code];
            if ($maxLevel !== null && (int) $a['level'] > $maxLevel) {
                continue;
            }
            $bal = $v['opening_debit'] + $v['debit'] - $v['opening_credit'] - $v['credit'];
            $row = [
                'code' => $code,
                'name' => $a['name'],
                'level' => (int) $a['level'],
                'opening' => $v['opening_debit'] - $v['opening_credit'],
                'debit' => $v['debit'],
                'credit' => $v['credit'],
                'balance_debit' => max($bal, 0),
                'balance_credit' => max(-$bal, 0),
            ];
            $out[] = $row;
            if ($a['parent'] === null) { // totais só pelas classes (nível de topo)
                $totals['opening_debit'] += $v['opening_debit'];
                $totals['opening_credit'] += $v['opening_credit'];
                $totals['debit'] += $v['debit'];
                $totals['credit'] += $v['credit'];
                $totals['balance_debit'] += $row['balance_debit'];
                $totals['balance_credit'] += $row['balance_credit'];
            }
        }
        return ['rows' => $out, 'totals' => $totals];
    }

    /** Extrato / razão de uma conta (ou prefixo) com saldo acumulado. */
    /** Extrato de conta. Com $limit > 0 devolve só uma página, com o saldo acumulado certo desde o início do período. */
    public static function ledger(Db $db, string $account, int $year, int $from = 1, int $to = 12, int $limit = 0, int $offset = 0): array
    {
        $account = preg_replace('/\D/', '', $account) ?? '';
        if ($account === '') {
            throw new UserError('Indique a conta.');
        }
        $name = $db->value('SELECT name FROM accounts WHERE code = ?', [$account]);
        $opening = (int) $db->value(
            "SELECT COALESCE(SUM(debit_cents - credit_cents), 0) FROM v_movements
             WHERE fiscal_year = ? AND account LIKE ? AND (journal_kind = 'abertura' OR month < ?)",
            [$year, $account . '%', $from]
        );
        $where = "fiscal_year = ? AND account LIKE ? AND journal_kind <> 'abertura' AND month BETWEEN ? AND ?";
        $args = [$year, $account . '%', $from, $to];
        $order = 'ORDER BY date, journal, number, line_id';
        $tot = $db->one("SELECT COUNT(*) AS n, COALESCE(SUM(debit_cents), 0) AS d, COALESCE(SUM(credit_cents), 0) AS c FROM v_movements WHERE $where", $args);
        $offset = max(0, $offset);
        $page = $limit > 0 ? ' LIMIT ' . (int) $limit . ' OFFSET ' . $offset : '';
        $rows = $db->all(
            "SELECT entry_id, date, journal, number, document, entry_description, description, account, debit_cents, credit_cents, third_party
             FROM v_movements WHERE $where $order$page",
            $args
        );
        // saldo antes da página: abertura + movimentos das linhas anteriores
        $bal = $opening;
        if ($limit > 0 && $offset > 0) {
            $bal += (int) $db->value("SELECT COALESCE(SUM(v), 0) FROM (SELECT debit_cents - credit_cents AS v FROM v_movements WHERE $where $order LIMIT $offset)", $args);
        }
        $pageStart = $bal;
        foreach ($rows as &$r) {
            $bal += (int) $r['debit_cents'] - (int) $r['credit_cents'];
            $r['balance'] = $bal;
        }
        unset($r);
        return ['account' => $account, 'name' => $name, 'opening' => $opening, 'page_start' => $pageStart, 'rows' => $rows,
            'debit' => (int) $tot['d'], 'credit' => (int) $tot['c'], 'closing' => $opening + (int) $tot['d'] - (int) $tot['c'],
            'total_rows' => (int) $tot['n'], 'offset' => $offset, 'limit' => $limit];
    }

    /**
     * Extrato de conta corrente de um terceiro (cliente, fornecedor ou outro) a partir da contabilidade: movimentos validados
     * do terceiro nas contas correntes (classe 2, exceto Estado 24), com saldo inicial do exercício e saldo acumulado.
     * O saldo segue o sentido natural: clientes = débito − crédito (o cliente deve); fornecedores = crédito − débito (devemos).
     */
    public static function partyStatement(Db $db, int $party, int $year, string $from, string $to, string $accountPrefix = ''): array
    {
        $tp = $db->one('SELECT * FROM third_parties WHERE id = ?', [$party]);
        if (!$tp) {
            throw new UserError('Terceiro não encontrado.');
        }
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new UserError('Período inválido.');
            }
        }
        if ($from > $to) {
            throw new UserError('A data inicial é posterior à data final.');
        }
        $sign = $tp['kind'] === 'F' ? -1 : 1;
        $acc = preg_replace('/\D/', '', $accountPrefix) ?? '';
        $accWhere = $acc !== '' ? 'account LIKE ?' : "account LIKE '2%' AND account NOT LIKE '24%'";
        $base = "fiscal_year = ? AND third_party = ? AND $accWhere";
        $args = $acc !== '' ? [$year, $party, $acc . '%'] : [$year, $party];
        $opening = (int) $db->value("SELECT COALESCE(SUM(debit_cents - credit_cents), 0) FROM v_movements WHERE $base AND (journal_kind = 'abertura' OR date < ?)", [...$args, $from]);
        $rows = $db->all(
            "SELECT entry_id, date, journal, number, document, entry_description, description, account, debit_cents, credit_cents
             FROM v_movements WHERE $base AND journal_kind <> 'abertura' AND date BETWEEN ? AND ? ORDER BY date, journal, number, line_id",
            [...$args, $from, $to]
        );
        $names = [];
        $bal = $opening;
        foreach ($rows as &$r) {
            $bal += (int) $r['debit_cents'] - (int) $r['credit_cents'];
            $r['balance'] = $bal * $sign;
            $names[$r['account']] ??= $db->value('SELECT name FROM accounts WHERE code = ?', [$r['account']]);
            $r['account_name'] = $names[$r['account']];
        }
        unset($r);
        $debit = array_sum(array_map('intval', array_column($rows, 'debit_cents')));
        $credit = array_sum(array_map('intval', array_column($rows, 'credit_cents')));
        $accounts = $db->all("SELECT DISTINCT m.account, a.name FROM v_movements m JOIN accounts a ON a.code = m.account WHERE m.fiscal_year = ? AND m.third_party = ? AND m.account LIKE '2%' AND m.account NOT LIKE '24%' ORDER BY m.account", [$year, $party]);
        return [
            'party' => $tp, 'year' => $year, 'from' => $from, 'to' => $to, 'account' => $acc, 'accounts' => $accounts,
            'sign' => $sign, 'opening' => $opening * $sign, 'closing' => $bal * $sign, 'debit' => $debit, 'credit' => $credit, 'rows' => $rows,
        ];
    }

    /** Diário: lançamentos validados com as linhas, por período. */
    /** Diário. Com $limit > 0 pagina por lançamentos (nunca corta um lançamento entre páginas). */
    public static function journal(Db $db, int $year, int $from = 1, int $to = 12, ?string $journal = null, int $limit = 0, int $offset = 0): array
    {
        $where = "e.status = 'validado' AND e.fiscal_year = ? AND CAST(substr(e.date, 6, 2) AS INTEGER) BETWEEN ? AND ?";
        $p = [$year, $from, $to];
        if ($journal) {
            $where .= ' AND e.journal = ?';
            $p[] = $journal;
        }
        $tot = $db->one("SELECT COUNT(*) AS n, COALESCE(SUM(e.total_cents), 0) AS t FROM entries e WHERE $where", $p);
        $offset = max(0, $offset);
        $page = $limit > 0 ? ' LIMIT ' . (int) $limit . ' OFFSET ' . $offset : '';
        $ids = array_map('intval', array_column($db->all("SELECT e.id FROM entries e WHERE $where ORDER BY e.date, e.journal, e.number$page", $p), 'id'));
        $rows = $ids ? $db->all(
            'SELECT m.entry_id, m.date, m.journal, m.number, m.document, m.entry_description, m.account, a.name AS account_name, m.description, m.debit_cents, m.credit_cents
             FROM v_movements m JOIN accounts a ON a.code = m.account
             WHERE m.entry_id IN (' . implode(',', $ids) . ') ORDER BY m.date, m.journal, m.number, m.line_id'
        ) : [];
        return ['rows' => $rows, 'debit' => (int) $tot['t'], 'credit' => (int) $tot['t'], 'total_entries' => (int) $tot['n'], 'offset' => $offset, 'limit' => $limit];
    }

    // ------------------------------------------------------------------ demonstrações financeiras

    private static function sumPrefixes(array $leaf, array $prefixes, string $mode): int
    {
        $total = 0;
        foreach ($leaf as $acc => $bal) {
            $acc = (string) $acc;
            foreach ($prefixes as $p) {
                if (str_starts_with($acc, $p)) {
                    $total += match ($mode) {
                        'net_debit' => $bal,
                        'net_credit' => -$bal,
                        'debit_bal' => max($bal, 0),
                        'credit_bal' => max(-$bal, 0),
                    };
                    break;
                }
            }
        }
        return $total;
    }

    /**
     * Balanço (modelo simplificado SNCRF) à data de fim do mês $to.
     * Os saldos das contas da classe 2 são separados por sinal (devedores no ativo, credores no passivo), conta a conta.
     */
    public static function balanceSheet(Db $db, int $year, int $to = 12): array
    {
        $leaf = self::leafBalances($db, $year, 1, $to, true, ['apuramento']);
        $s = static fn(array $p, string $m) => self::sumPrefixes($leaf, $p, $m);
        $result = $s(['6', '7', '8'], 'net_credit');

        $sections = [
            ['key' => 'anc', 'title' => 'Ativo não corrente', 'lines' => [
                ['Ativos fixos tangíveis', $s(['43'], 'net_debit')],
                ['Propriedades de investimento', $s(['42'], 'net_debit')],
                ['Ativos intangíveis', $s(['44'], 'net_debit')],
                ['Investimentos financeiros', $s(['41'], 'net_debit')],
                ['Investimentos em curso', $s(['45'], 'net_debit')],
            ]],
            ['key' => 'ac', 'title' => 'Ativo corrente', 'lines' => [
                ['Inventários e ativos biológicos', $s(['3'], 'net_debit')],
                ['Clientes', $s(['21'], 'debit_bal')],
                ['Estado e outros entes públicos', $s(['24'], 'debit_bal')],
                ['Accionistas / sócios', $s(['26'], 'debit_bal')],
                ['Outras contas a receber', $s(['22', '23', '25', '27'], 'debit_bal')],
                ['Diferimentos', $s(['28'], 'debit_bal')],
                ['Meios financeiros líquidos', $s(['1'], 'net_debit')],
            ]],
            ['key' => 'cp', 'title' => 'Capital próprio', 'lines' => [
                ['Capital', $s(['51'], 'net_credit')],
                ['Acções (quotas) próprias', $s(['52'], 'net_credit')],
                ['Outros instrumentos de capital próprio e prémios', $s(['53', '54'], 'net_credit')],
                ['Reservas', $s(['55'], 'net_credit')],
                ['Resultados transitados', $s(['56'], 'net_credit')],
                ['Ajustamentos, excedentes e outras variações', $s(['57', '58', '59'], 'net_credit')],
                ['Resultado líquido do período', $result],
            ]],
            ['key' => 'pnc', 'title' => 'Passivo não corrente', 'lines' => [
                ['Provisões', $s(['29'], 'net_credit')],
                ['Financiamentos obtidos', $s(['25'], 'credit_bal')],
            ]],
            ['key' => 'pc', 'title' => 'Passivo corrente', 'lines' => [
                ['Fornecedores', $s(['22'], 'credit_bal')],
                ['Estado e outros entes públicos', $s(['24'], 'credit_bal')],
                ['Accionistas / sócios', $s(['26'], 'credit_bal')],
                ['Outras contas a pagar', $s(['21', '23', '27'], 'credit_bal')],
                ['Diferimentos', $s(['28'], 'credit_bal')],
            ]],
        ];
        $tot = [];
        foreach ($sections as &$sec) {
            $sec['lines'] = array_map(static fn($l) => ['label' => $l[0], 'value' => $l[1]], $sec['lines']);
            $sec['total'] = array_sum(array_column($sec['lines'], 'value'));
            $tot[$sec['key']] = $sec['total'];
        }
        $ativo = $tot['anc'] + $tot['ac'];
        $passivo = $tot['pnc'] + $tot['pc'];
        return [
            'year' => $year,
            'date' => sprintf('%04d-%02d-%02d', $year, $to, (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $to)))),
            'sections' => $sections,
            'total_ativo' => $ativo,
            'total_capital_proprio' => $tot['cp'],
            'total_passivo' => $passivo,
            'difference' => $ativo - $tot['cp'] - $passivo,
        ];
    }

    /** Demonstração dos resultados por naturezas (meses $from a $to, sem o apuramento). */
    public static function incomeStatement(Db $db, int $year, int $from = 1, int $to = 12): array
    {
        $leaf = self::leafBalances($db, $year, $from, $to, false, ['apuramento']);
        return self::incomeFromLeaf($leaf, $year, $from, $to);
    }

    public static function incomeFromLeaf(array $leaf, int $year, int $from, int $to): array
    {
        $c = static fn(array $p) => self::sumPrefixes($leaf, $p, 'net_credit'); // rendimentos +, gastos −
        $lines = [
            ['key' => 'vn', 'label' => 'Vendas e serviços prestados', 'value' => $c(['71', '72'])],
            ['key' => 'sub', 'label' => 'Subsídios à exploração', 'value' => $c(['75'])],
            ['key' => 'var', 'label' => 'Variação nos inventários da produção', 'value' => $c(['73'])],
            ['key' => 'tpe', 'label' => 'Trabalhos para a própria entidade', 'value' => $c(['74'])],
            ['key' => 'cmv', 'label' => 'Custo das mercadorias vendidas e matérias consumidas', 'value' => $c(['61'])],
            ['key' => 'fse', 'label' => 'Fornecimentos e serviços externos', 'value' => $c(['62'])],
            ['key' => 'pes', 'label' => 'Gastos com o pessoal', 'value' => $c(['63'])],
            ['key' => 'imp', 'label' => 'Imparidades, provisões e reversões', 'value' => $c(['65', '67', '76'])],
            ['key' => 'jv', 'label' => 'Aumentos / reduções de justo valor', 'value' => $c(['66', '77'])],
            ['key' => 'or', 'label' => 'Outros rendimentos', 'value' => $c(['78'])],
            ['key' => 'og', 'label' => 'Outros gastos', 'value' => $c(['68'])],
        ];
        $ebitda = array_sum(array_column($lines, 'value'));
        $dep = $c(['64']);
        $ebit = $ebitda + $dep;
        $jo = $c(['79']);
        $js = $c(['69']);
        $rai = $ebit + $jo + $js;
        $irc = $c(['812']);
        $rl = $rai + $irc;
        return [
            'year' => $year, 'from' => $from, 'to' => $to,
            'lines' => $lines,
            'ebitda' => $ebitda,
            'depreciacoes' => $dep,
            'ebit' => $ebit,
            'juros_obtidos' => $jo,
            'juros_suportados' => $js,
            'resultado_antes_impostos' => $rai,
            'imposto' => $irc,
            'resultado_liquido' => $rl,
        ];
    }
}
