<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Módulo de estatísticas e análise. Tudo é calculado localmente a partir dos lançamentos validados.
 * Convenção de sinais: rendimentos e resultados positivos; gastos devolvidos como valores positivos nas séries de gastos.
 */
final class Stats
{
    private static function hasYear(Db $db, int $year): bool
    {
        return (bool) $db->value('SELECT 1 FROM fiscal_years WHERE year = ?', [$year]);
    }

    /** Último mês com movimentos no exercício (ou 12). */
    public static function lastMonth(Db $db, int $year): int
    {
        $m = (int) $db->value("SELECT MAX(month) FROM v_movements WHERE fiscal_year = ? AND journal_kind = 'normal'", [$year]);
        return $m ?: 12;
    }

    /** Séries mensais (1..12) de vendas, gastos, resultado e meios financeiros no fim do mês. */
    public static function monthly(Db $db, int $year): array
    {
        $series = ['vendas' => array_fill(1, 12, 0), 'rendimentos' => array_fill(1, 12, 0), 'gastos' => array_fill(1, 12, 0),
            'resultado' => array_fill(1, 12, 0), 'tesouraria' => array_fill(1, 12, 0), 'iva' => array_fill(1, 12, 0)];
        $rows = $db->all(
            "SELECT month,
                    SUM(CASE WHEN account LIKE '71%' OR account LIKE '72%' THEN credit_cents - debit_cents ELSE 0 END) AS vendas,
                    SUM(CASE WHEN account LIKE '7%' THEN credit_cents - debit_cents ELSE 0 END) AS rend,
                    SUM(CASE WHEN account LIKE '6%' THEN debit_cents - credit_cents ELSE 0 END) AS gastos,
                    SUM(CASE WHEN account LIKE '812%' THEN debit_cents - credit_cents ELSE 0 END) AS irc,
                    SUM(CASE WHEN account LIKE '2433%' THEN credit_cents - debit_cents WHEN account LIKE '2432%' OR account LIKE '2434%' THEN credit_cents - debit_cents ELSE 0 END) AS iva
             FROM v_movements WHERE fiscal_year = ? AND journal_kind = 'normal' AND COALESCE(entry_description,'') NOT LIKE 'Apuramento de IVA%'
             GROUP BY month",
            [$year]
        );
        foreach ($rows as $r) {
            $m = (int) $r['month'];
            $series['vendas'][$m] = (int) $r['vendas'];
            $series['rendimentos'][$m] = (int) $r['rend'];
            $series['gastos'][$m] = (int) $r['gastos'];
            $series['resultado'][$m] = (int) $r['rend'] - (int) $r['gastos'] - (int) $r['irc'];
            $series['iva'][$m] = (int) $r['iva'];
        }
        // meios financeiros acumulados (abertura + movimentos)
        $open = (int) $db->value("SELECT COALESCE(SUM(debit_cents - credit_cents),0) FROM v_movements WHERE fiscal_year = ? AND account LIKE '1%' AND journal_kind = 'abertura'", [$year]);
        $byMonth = [];
        foreach ($db->all("SELECT month, SUM(debit_cents - credit_cents) AS v FROM v_movements WHERE fiscal_year = ? AND account LIKE '1%' AND journal_kind <> 'abertura' GROUP BY month", [$year]) as $r) {
            $byMonth[(int) $r['month']] = (int) $r['v'];
        }
        $acc = $open;
        for ($m = 1; $m <= 12; $m++) {
            $acc += $byMonth[$m] ?? 0;
            $series['tesouraria'][$m] = $acc;
        }
        foreach ($series as $k => $v) {
            $series[$k] = array_values($v);
        }
        return $series;
    }

    private static function ytd(array $series, int $to): int
    {
        return array_sum(array_slice($series, 0, $to));
    }

    /** Painel: indicadores do ano até ao mês $to, comparados com o mesmo período do ano anterior. */
    public static function dashboard(Db $db, int $year, ?int $to = null): array
    {
        $to ??= self::lastMonth($db, $year);
        $is = Reports::incomeStatement($db, $year, 1, $to);
        $cur = self::monthly($db, $year);
        $prevYear = $year - 1;
        $hasPrev = self::hasYear($db, $prevYear);
        $prev = $hasPrev ? self::monthly($db, $prevYear) : null;
        $isPrev = $hasPrev ? Reports::incomeStatement($db, $prevYear, 1, $to) : null;
        $vn = $is['lines'][0]['value'];
        $cmv = -$is['lines'][4]['value'];
        $kpi = static function (string $key, string $label, int $value, ?int $prevValue, string $hint = '') {
            return ['key' => $key, 'label' => $label, 'value' => $value, 'previous' => $prevValue,
                'change' => ($prevValue !== null && $prevValue !== 0) ? round(($value - $prevValue) / abs($prevValue) * 100, 1) : null, 'hint' => $hint];
        };
        $vat = Vat::settlement($db, $year, 1, $to);
        $kpis = [
            $kpi('vn', 'Volume de negócios', $vn, $isPrev ? $isPrev['lines'][0]['value'] : null, 'Contas 71 e 72'),
            $kpi('mb', 'Margem bruta', $vn - $cmv, $isPrev ? $isPrev['lines'][0]['value'] + $isPrev['lines'][4]['value'] : null, 'Vendas − CMVMC'),
            $kpi('ebitda', 'EBITDA', $is['ebitda'], $isPrev['ebitda'] ?? null, 'Resultado antes de depreciações, juros e impostos'),
            $kpi('rl', 'Resultado líquido', $is['resultado_liquido'], $isPrev['resultado_liquido'] ?? null),
            $kpi('mfl', 'Meios financeiros líquidos', $cur['tesouraria'][$to - 1], $prev ? $prev['tesouraria'][$to - 1] : null, 'Classe 1 no fim do período'),
            $kpi('iva', 'IVA apurado no período', $vat['resultado'], null, $vat['resultado'] >= 0 ? 'A pagar' : 'A recuperar'),
        ];
        return [
            'year' => $year, 'to' => $to, 'has_previous' => $hasPrev,
            'kpis' => $kpis,
            'monthly' => $cur,
            'monthly_previous' => $prev,
            'margin_pct' => $vn ? round(($vn - $cmv) / $vn * 100, 1) : null,
            'net_margin_pct' => $vn ? round($is['resultado_liquido'] / $vn * 100, 1) : null,
            'assets' => BusinessStats::assets($db, $year),
        ];
    }

    /** Rácios financeiros a partir do balanço e da DR (ano até ao mês $to). */
    public static function ratios(Db $db, int $year, ?int $to = null): array
    {
        $to ??= self::lastMonth($db, $year);
        $bs = Reports::balanceSheet($db, $year, $to);
        $is = Reports::incomeStatement($db, $year, 1, $to);
        $sec = [];
        foreach ($bs['sections'] as $s) {
            $sec[$s['key']] = $s;
        }
        $line = static function (string $key, string $label) use ($sec): int {
            foreach ($sec[$key]['lines'] as $l) {
                if ($l['label'] === $label) {
                    return $l['value'];
                }
            }
            return 0;
        };
        $ac = $sec['ac']['total'];
        $pc = $sec['pc']['total'];
        $inv = $line('ac', 'Inventários e ativos biológicos');
        $mfl = $line('ac', 'Meios financeiros líquidos');
        $clientes = $line('ac', 'Clientes');
        $fornecedores = $line('pc', 'Fornecedores');
        $ativo = $bs['total_ativo'];
        $cp = $bs['total_capital_proprio'];
        $passivo = $bs['total_passivo'];
        $vn = $is['lines'][0]['value'];
        $rl = $is['resultado_liquido'];
        $compras = -($is['lines'][4]['value'] + $is['lines'][5]['value']); // CMVMC + FSE (aproximação)
        $days = $to * 30.4;
        $terms = BusinessStats::terms($db, $year, 1, $to);
        $div = static fn(int|float $a, int|float $b): ?float => $b != 0 ? round($a / $b, 2) : null;
        $pct = static fn(int|float $a, int|float $b): ?float => $b != 0 ? round($a / $b * 100, 1) : null;
        $r = static fn(string $group, string $key, string $label, ?float $value, string $unit, string $formula, string $reading) =>
            compact('group', 'key', 'label', 'value', 'unit', 'formula', 'reading');
        return [
            'year' => $year, 'to' => $to,
            'ratios' => [
                $r('Liquidez', 'liq_geral', 'Liquidez geral', $div($ac, $pc), 'x', 'Ativo corrente / Passivo corrente', 'Acima de 1: o ativo corrente cobre as dívidas de curto prazo.'),
                $r('Liquidez', 'liq_reduzida', 'Liquidez reduzida', $div($ac - $inv, $pc), 'x', '(Ativo corrente − Inventários) / Passivo corrente', 'Capacidade de pagar sem vender stock.'),
                $r('Liquidez', 'liq_imediata', 'Liquidez imediata', $div($mfl, $pc), 'x', 'Meios financeiros líquidos / Passivo corrente', 'Parte das dívidas de curto prazo pagável já.'),
                $r('Estrutura', 'autonomia', 'Autonomia financeira', $pct($cp, $ativo), '%', 'Capital próprio / Ativo', 'Percentagem do ativo financiada por capitais próprios.'),
                $r('Estrutura', 'solvabilidade', 'Solvabilidade', $div($cp, $passivo), 'x', 'Capital próprio / Passivo', 'Acima de 1: os capitais próprios superam as dívidas.'),
                $r('Estrutura', 'endividamento', 'Endividamento', $pct($passivo, $ativo), '%', 'Passivo / Ativo', 'Percentagem do ativo financiada por terceiros.'),
                $r('Rendibilidade', 'roe', 'Rendibilidade dos capitais próprios', $pct($rl, $cp), '%', 'Resultado líquido / Capital próprio', 'Retorno para os sócios no período.'),
                $r('Rendibilidade', 'roa', 'Rendibilidade do ativo', $pct($rl, $ativo), '%', 'Resultado líquido / Ativo', 'Eficiência na utilização dos ativos.'),
                $r('Rendibilidade', 'ros', 'Rendibilidade das vendas', $pct($rl, $vn), '%', 'Resultado líquido / Volume de negócios', 'Lucro por cada 100 ECV vendidos.'),
                $terms['receivable']['avg_days'] !== null
                    ? $r('Atividade', 'pmr', 'Prazo médio de recebimento', (float) $terms['receivable']['avg_days'], 'dias', 'Dias entre a fatura e o recibo, ponderados pelo valor (documentos de venda)', 'Dias que os clientes demoram a pagar. ' . self::lateText($terms['receivable']))
                    : $r('Atividade', 'pmr', 'Prazo médio de recebimento', $vn ? round($clientes / ($vn * 1.15) * $days) : null, 'dias', 'Clientes / (Vendas × 1,15) × dias', 'Dias que os clientes demoram a pagar (assume IVA a 15%).'),
                $terms['payable']['avg_days'] !== null
                    ? $r('Atividade', 'pmp', 'Prazo médio de pagamento', (float) $terms['payable']['avg_days'], 'dias', 'Dias entre a fatura e o pagamento, ponderados pelo valor (compras)', 'Dias que a empresa demora a pagar. ' . self::lateText($terms['payable']))
                    : $r('Atividade', 'pmp', 'Prazo médio de pagamento', $compras ? round($fornecedores / ($compras * 1.15) * $days) : null, 'dias', 'Fornecedores / ((CMVMC + FSE) × 1,15) × dias', 'Dias que a empresa demora a pagar (aproximação).'),
            ],
            'base' => ['ativo' => $ativo, 'capital_proprio' => $cp, 'passivo' => $passivo, 'ativo_corrente' => $ac, 'passivo_corrente' => $pc, 'vn' => $vn, 'rl' => $rl],
        ];
    }

    private static function lateText(array $t): string
    {
        return $t['on_time_pct'] === null ? '' : sprintf('%s%% do valor foi liquidado dentro do prazo; atraso médio de %d %s.', str_replace('.', ',', (string) $t['on_time_pct']), $t['avg_late'], $t['avg_late'] === 1 ? 'dia' : 'dias');
    }

    /** Estrutura de gastos por conta de 2.º e 3.º grau. */
    public static function costs(Db $db, int $year, int $from = 1, int $to = 12): array
    {
        $rows = $db->all(
            "SELECT substr(m.account, 1, 2) AS g2, substr(m.account, 1, 3) AS g3, SUM(m.debit_cents - m.credit_cents) AS v
             FROM v_movements m WHERE m.fiscal_year = ? AND m.account LIKE '6%' AND m.journal_kind = 'normal' AND m.month BETWEEN ? AND ?
             GROUP BY g3 ORDER BY g3",
            [$year, $from, $to]
        );
        $names = [];
        foreach ($db->all("SELECT code, name FROM accounts WHERE class = 6 AND level <= 3") as $a) {
            $names[$a['code']] = $a['name'];
        }
        $groups = [];
        $total = 0;
        foreach ($rows as $r) {
            $v = (int) $r['v'];
            $total += $v;
            $groups[$r['g2']] ??= ['code' => $r['g2'], 'name' => $names[$r['g2']] ?? $r['g2'], 'value' => 0, 'detail' => []];
            $groups[$r['g2']]['value'] += $v;
            $groups[$r['g2']]['detail'][] = ['code' => $r['g3'], 'name' => $names[$r['g3']] ?? $r['g3'], 'value' => $v];
        }
        $groups = array_values($groups);
        usort($groups, static fn($a, $b) => $b['value'] <=> $a['value']);
        foreach ($groups as &$g) {
            $g['pct'] = $total ? round($g['value'] / $total * 100, 1) : 0;
        }
        return ['total' => $total, 'groups' => $groups];
    }

    /** Maiores clientes (vendas 71/72) ou fornecedores (compras 31/61/62/43/44) por terceiro do lançamento. */
    public static function topParties(Db $db, int $year, string $kind = 'C', int $limit = 10): array
    {
        $accounts = $kind === 'C' ? "(m.account LIKE '71%' OR m.account LIKE '72%')" : "(m.account LIKE '31%' OR m.account LIKE '61%' OR m.account LIKE '62%' OR m.account LIKE '43%' OR m.account LIKE '44%')";
        $sign = $kind === 'C' ? 'm.credit_cents - m.debit_cents' : 'm.debit_cents - m.credit_cents';
        $rows = $db->all(
            "SELECT t.id, t.name, t.nif, SUM($sign) AS value, COUNT(DISTINCT m.entry_id) AS documents
             FROM v_movements m
             JOIN (SELECT entry_id, MIN(third_party) AS tp FROM entry_lines WHERE third_party IS NOT NULL GROUP BY entry_id) x ON x.entry_id = m.entry_id
             JOIN third_parties t ON t.id = x.tp
             WHERE m.fiscal_year = ? AND m.journal_kind = 'normal' AND $accounts
             GROUP BY t.id ORDER BY value DESC LIMIT " . max(1, min(100, $limit)),
            [$year]
        );
        $total = (int) $db->value("SELECT COALESCE(SUM($sign),0) FROM v_movements m WHERE m.fiscal_year = ? AND m.journal_kind = 'normal' AND $accounts", [$year]);
        foreach ($rows as &$r) {
            $r['value'] = (int) $r['value'];
            $r['pct'] = $total ? round($r['value'] / $total * 100, 1) : 0;
        }
        return ['total' => $total, 'rows' => $rows, 'concentration_top3' => $total ? round(array_sum(array_column(array_slice($rows, 0, 3), 'value')) / $total * 100, 1) : 0];
    }

    /** Saldos em aberto de clientes (21) e fornecedores (22) por terceiro, com a data do movimento mais antigo em aberto (FIFO). */
    public static function openBalances(Db $db, int $year, string $kind = 'C'): array
    {
        $prefix = $kind === 'C' ? '21' : '22';
        $rows = $db->all(
            "SELECT m.third_party, m.date, m.debit_cents, m.credit_cents FROM v_movements m
             WHERE m.fiscal_year = ? AND m.account LIKE ? AND m.third_party IS NOT NULL ORDER BY m.date",
            [$year, $prefix . '%']
        );
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['third_party']][] = $r;
        }
        $today = new \DateTimeImmutable(min(date('Y-m-d'), "{$year}-12-31"));
        $out = [];
        $buckets = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '>90' => 0];
        foreach ($by as $tp => $movs) {
            // documentos que aumentam o saldo (débito em clientes, crédito em fornecedores) liquidados por FIFO
            $open = [];
            $pay = 0;
            foreach ($movs as $m) {
                $inc = $kind === 'C' ? (int) $m['debit_cents'] : (int) $m['credit_cents'];
                $dec = $kind === 'C' ? (int) $m['credit_cents'] : (int) $m['debit_cents'];
                if ($inc > 0) {
                    $open[] = ['date' => $m['date'], 'amount' => $inc];
                }
                $pay += $dec;
            }
            foreach ($open as &$o) {
                $use = min($pay, $o['amount']);
                $o['amount'] -= $use;
                $pay -= $use;
            }
            unset($o);
            $open = array_values(array_filter($open, static fn($o) => $o['amount'] > 0));
            $balance = array_sum(array_column($open, 'amount')) - $pay;
            if ($balance === 0) {
                continue;
            }
            $oldest = $open[0]['date'] ?? null;
            foreach ($open as $o) {
                $age = (int) $today->diff(new \DateTimeImmutable($o['date']))->days;
                $k = $age <= 30 ? '0-30' : ($age <= 60 ? '31-60' : ($age <= 90 ? '61-90' : '>90'));
                $buckets[$k] += $o['amount'];
            }
            $t = $db->one('SELECT name, nif FROM third_parties WHERE id = ?', [$tp]);
            $out[] = ['id' => $tp, 'name' => $t['name'] ?? '?', 'nif' => $t['nif'] ?? null, 'balance' => $balance, 'oldest' => $oldest,
                'age_days' => $oldest ? (int) $today->diff(new \DateTimeImmutable($oldest))->days : null];
        }
        usort($out, static fn($a, $b) => $b['balance'] <=> $a['balance']);
        return ['rows' => $out, 'buckets' => $buckets, 'total' => array_sum(array_column($out, 'balance'))];
    }

    /** Previsão do fecho do exercício: sazonal (com base no ano anterior) ou linear (média dos meses decorridos). */
    public static function forecast(Db $db, int $year): array
    {
        $to = self::lastMonth($db, $year);
        $cur = self::monthly($db, $year);
        $prevYear = $year - 1;
        $prev = self::hasYear($db, $prevYear) ? self::monthly($db, $prevYear) : null;
        $out = ['year' => $year, 'months_elapsed' => $to, 'method' => 'linear', 'items' => []];
        foreach (['vendas' => 'Volume de negócios', 'gastos' => 'Gastos', 'resultado' => 'Resultado'] as $k => $label) {
            $ytd = self::ytd($cur[$k], $to);
            $projection = $to > 0 ? (int) round($ytd / $to * 12) : 0;
            $method = 'linear';
            if ($prev && $k !== 'resultado') {
                $pYtd = self::ytd($prev[$k], $to);
                $pTotal = array_sum($prev[$k]);
                if ($pYtd > 0 && $pTotal > 0) {
                    $projection = (int) round($ytd * $pTotal / $pYtd);
                    $method = 'sazonal';
                }
            }
            $out['items'][$k] = ['label' => $label, 'ytd' => $ytd, 'projection' => $projection, 'method' => $method, 'previous_total' => $prev ? array_sum($prev[$k]) : null];
        }
        if ($out['items']['vendas']['method'] === 'sazonal') {
            $out['method'] = 'sazonal';
            $out['items']['resultado']['projection'] = $out['items']['vendas']['projection'] - $out['items']['gastos']['projection'];
            $out['items']['resultado']['method'] = 'sazonal';
        }
        return $out;
    }

    // ------------------------------------------------------------------ orçamento

    public static function saveBudget(Db $db, int $year, string $account, array $months): void
    {
        if (!$db->value('SELECT 1 FROM accounts WHERE code = ?', [$account])) {
            throw new UserError('Conta inexistente.');
        }
        if (!in_array($account[0], ['6', '7'], true)) {
            throw new UserError('O orçamento aceita contas de gastos (6) e rendimentos (7).');
        }
        $db->transaction(static function () use ($db, $year, $account, $months) {
            $db->run('DELETE FROM budgets WHERE year = ? AND account = ?', [$year, $account]);
            for ($m = 1; $m <= 12; $m++) {
                $v = Money::toCents($months[$m - 1] ?? 0);
                if ($v !== 0) {
                    $db->insert('budgets', ['account' => $account, 'year' => $year, 'month' => $m, 'amount_cents' => $v]);
                }
            }
        });
    }

    /** Orçamento contra real por conta orçamentada (real com o sinal natural: gastos e rendimentos positivos). */
    public static function budgetVsActual(Db $db, int $year, ?int $to = null): array
    {
        $to ??= self::lastMonth($db, $year);
        $rows = [];
        foreach ($db->all('SELECT b.account, a.name, b.month, b.amount_cents FROM budgets b JOIN accounts a ON a.code = b.account WHERE b.year = ? ORDER BY b.account, b.month', [$year]) as $b) {
            $rows[$b['account']] ??= ['account' => $b['account'], 'name' => $b['name'], 'budget' => array_fill(0, 12, 0)];
            $rows[$b['account']]['budget'][(int) $b['month'] - 1] = (int) $b['amount_cents'];
        }
        foreach ($rows as $acc => &$r) {
            $acc = (string) $acc;
            $sign = $acc[0] === '7' ? 'credit_cents - debit_cents' : 'debit_cents - credit_cents';
            $actual = array_fill(0, 12, 0);
            foreach ($db->all("SELECT month, SUM($sign) AS v FROM v_movements WHERE fiscal_year = ? AND account LIKE ? AND journal_kind = 'normal' GROUP BY month", [$year, $acc . '%']) as $m) {
                $actual[(int) $m['month'] - 1] = (int) $m['v'];
            }
            $r['actual'] = $actual;
            $r['budget_ytd'] = array_sum(array_slice($r['budget'], 0, $to));
            $r['actual_ytd'] = array_sum(array_slice($actual, 0, $to));
            $r['budget_year'] = array_sum($r['budget']);
            $r['deviation'] = $r['actual_ytd'] - $r['budget_ytd'];
            $r['deviation_pct'] = $r['budget_ytd'] ? round($r['deviation'] / $r['budget_ytd'] * 100, 1) : null;
            // desfavorável: gastos acima ou rendimentos abaixo do orçado
            $r['unfavorable'] = $acc[0] === '6' ? $r['deviation'] > 0 : $r['deviation'] < 0;
        }
        return ['year' => $year, 'to' => $to, 'rows' => array_values($rows)];
    }

    // ------------------------------------------------------------------ indicadores personalizados

    /**
     * Avalia uma fórmula sobre saldos do ano até ao mês $to (sem apuramento).
     * Funções: C(71,72) = saldo credor líquido; D(62) = saldo devedor líquido. Operadores + − × / e parênteses.
     */
    public static function evaluate(Db $db, string $formula, int $year, int $to = 12): float
    {
        $leaf = Reports::leafBalances($db, $year, 1, $to, true, ['apuramento']);
        $tokens = [];
        if (!preg_match_all('/\s*(?:(\d+(?:[.,]\d+)?)|([CD])\(([\d,\s]+)\)|([-+*\/()]))/A', $formula, $m, PREG_SET_ORDER)
            || implode('', array_map(static fn($x) => $x[0], $m)) !== $formula) {
            throw new UserError('Fórmula inválida. Use por exemplo: C(71,72) - D(61,62)');
        }
        foreach ($m as $t) {
            if (($t[1] ?? '') !== '') {
                $tokens[] = (float) str_replace(',', '.', $t[1]);
            } elseif (($t[2] ?? '') !== '') {
                $sum = 0;
                $prefixes = array_filter(array_map('trim', explode(',', $t[3])));
                foreach ($leaf as $acc => $bal) {
                    foreach ($prefixes as $p) {
                        if (str_starts_with((string) $acc, $p)) {
                            $sum += $bal;
                            break;
                        }
                    }
                }
                $tokens[] = (float) (($t[2] === 'C' ? -$sum : $sum) / 100);
            } else {
                $tokens[] = $t[4];
            }
        }
        $pos = 0;
        $result = self::parseExpr($tokens, $pos);
        if ($pos !== count($tokens)) {
            throw new UserError('Fórmula inválida.');
        }
        return round($result, 2);
    }

    private static function parseExpr(array $t, int &$p): float
    {
        $v = self::parseTerm($t, $p);
        while ($p < count($t) && ($t[$p] === '+' || $t[$p] === '-')) {
            $op = $t[$p++];
            $r = self::parseTerm($t, $p);
            $v = $op === '+' ? $v + $r : $v - $r;
        }
        return $v;
    }

    private static function parseTerm(array $t, int &$p): float
    {
        $v = self::parseFactor($t, $p);
        while ($p < count($t) && ($t[$p] === '*' || $t[$p] === '/')) {
            $op = $t[$p++];
            $r = self::parseFactor($t, $p);
            if ($op === '/' && $r == 0) {
                throw new UserError('Divisão por zero na fórmula.');
            }
            $v = $op === '*' ? $v * $r : $v / $r;
        }
        return $v;
    }

    private static function parseFactor(array $t, int &$p): float
    {
        $tok = $t[$p] ?? null;
        if ($tok === '-') {
            $p++;
            return -self::parseFactor($t, $p);
        }
        if ($tok === '(') {
            $p++;
            $v = self::parseExpr($t, $p);
            if (($t[$p] ?? null) !== ')') {
                throw new UserError('Falta fechar um parêntese na fórmula.');
            }
            $p++;
            return $v;
        }
        if (is_float($tok)) {
            $p++;
            return $tok;
        }
        throw new UserError('Fórmula inválida.');
    }

    public static function customIndicators(Db $db, int $year, ?int $to = null): array
    {
        $to ??= self::lastMonth($db, $year);
        $list = json_decode((string) ($db->meta('custom_indicators') ?? '[]'), true) ?: [];
        foreach ($list as &$i) {
            try {
                $i['value'] = self::evaluate($db, $i['formula'], $year, $to);
                $i['error'] = null;
            } catch (\Throwable $e) {
                $i['value'] = null;
                $i['error'] = $e->getMessage();
            }
        }
        return $list;
    }

    public static function saveCustomIndicators(Db $db, array $list): void
    {
        $clean = [];
        foreach ($list as $i) {
            $name = trim((string) ($i['name'] ?? ''));
            $formula = trim((string) ($i['formula'] ?? ''));
            if ($name === '' || $formula === '') {
                continue;
            }
            $clean[] = ['name' => $name, 'formula' => $formula, 'unit' => (string) ($i['unit'] ?? 'ECV')];
        }
        $db->setMeta('custom_indicators', json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------ multiempresa (gabinetes)

    public static function portfolio(): array
    {
        $out = [];
        foreach (Companies::list() as $c) {
            if (!empty($c['error'])) {
                $out[] = $c + ['alerts' => [$c['error']]];
                continue;
            }
            try {
                $db = Companies::open($c['id']);
                $year = $c['years'] ? max($c['years']) : (int) date('Y');
                $to = self::lastMonth($db, $year);
                $is = Reports::incomeStatement($db, $year, 1, $to);
                $drafts = (int) $db->value("SELECT COUNT(*) FROM entries WHERE status = 'rascunho'");
                $last = $db->value("SELECT MAX(date) FROM entries WHERE status = 'validado'");
                $lastBackup = $db->value("SELECT MAX(ts) FROM audit_log WHERE action = 'copia.seguranca'");
                $alerts = [];
                if ($drafts) {
                    $alerts[] = "{$drafts} lançamento(s) em rascunho";
                }
                if (!$lastBackup || strtotime((string) $lastBackup) < strtotime('-7 days')) {
                    $alerts[] = 'Sem cópia de segurança nos últimos 7 dias';
                }
                $chain = Ledger::chainStatus($db);
                if (!$chain['ok']) {
                    $alerts[] = 'Cadeia de integridade quebrada';
                }
                $out[] = $c + ['year' => $year, 'to' => $to, 'vn' => $is['lines'][0]['value'], 'rl' => $is['resultado_liquido'],
                    'drafts' => $drafts, 'last_entry' => $last, 'last_backup' => $lastBackup, 'alerts' => $alerts];
            } catch (\Throwable $e) {
                $out[] = $c + ['alerts' => [$e->getMessage()]];
            }
        }
        return $out;
    }
}
