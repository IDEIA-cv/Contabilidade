<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Estatísticas a partir dos documentos (faturação, compras, imobilizado), complementares às de Stats (lançamentos).
 *
 * Vendas: documentos emitidos FT, FR, TV e ND somam; NC subtrai (valor e quantidade). Valores sem IVA.
 * Custo dos artigos: custo médio das compras registadas (FC, FP) com esse artigo até ao fim do período.
 * Prazos: média ponderada pelo valor dos dias entre a fatura e o recibo/pagamento que a liquida.
 */
final class BusinessStats
{
    private const SALES_SIGN = "CASE WHEN d.type = 'NC' THEN -1 ELSE 1 END";
    private const SALES_TYPES = "('FT','FR','TV','ND','NC')";

    /** Datas do período: [início, fim] em ISO. */
    private static function range(int $year, int $from, int $to): array
    {
        $from = max(1, min(12, $from));
        $to = max($from, min(12, $to));
        return [sprintf('%04d-%02d-01', $year, $from), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $to)))];
    }

    private static function pct(int $part, int $total): float
    {
        return $total ? round($part / $total * 100, 1) : 0.0;
    }

    // ================================================================== vendas

    /**
     * Vendas do período: totais (com o mesmo período do ano anterior), série mensal, por artigo, por família e por cliente.
     * Filtros: family (id, 0 = sem família), party (id do cliente).
     */
    public static function sales(Db $db, int $year, int $from = 1, int $to = 12, array $f = []): array
    {
        [$d1, $d2] = self::range($year, $from, $to);
        $where = ["d.status = 'emitido'", 'd.type IN ' . self::SALES_TYPES, 'd.date BETWEEN ? AND ?'];
        $args = [$d1, $d2];
        if (isset($f['family']) && $f['family'] !== '') {
            if ((int) $f['family'] === 0) {
                $where[] = 'i.family_id IS NULL';
            } else {
                $where[] = 'i.family_id = ?';
                $args[] = (int) $f['family'];
            }
        }
        if (!empty($f['party'])) {
            $where[] = 'd.third_party = ?';
            $args[] = (int) $f['party'];
        }
        $w = implode(' AND ', $where);
        $sign = self::SALES_SIGN;
        $base = "FROM sales_doc_lines l JOIN sales_docs d ON d.id = l.doc_id LEFT JOIN items i ON i.id = l.item_id
                 LEFT JOIN item_families fa ON fa.id = i.family_id LEFT JOIN third_parties t ON t.id = d.third_party WHERE $w";

        $cost = self::itemCosts($db, $d2);

        // por artigo (linhas sem artigo agrupadas pela descrição não fazem sentido: ficam numa linha "Sem artigo")
        $rows = $db->all(
            "SELECT l.item_id, i.code, COALESCE(i.name, 'Linhas sem artigo') AS name, i.kind, i.unit, fa.name AS family,
                    SUM($sign * l.qty_milli) AS qty_milli, SUM($sign * l.net_cents) AS net, COUNT(DISTINCT d.id) AS docs
             $base GROUP BY l.item_id ORDER BY net DESC",
            $args
        );
        $total = (int) array_sum(array_column($rows, 'net'));
        $marginTotal = 0;
        $netWithCost = 0;
        foreach ($rows as &$r) {
            $r['net'] = (int) $r['net'];
            $r['qty'] = round((int) $r['qty_milli'] / 1000, 3);
            unset($r['qty_milli']);
            $r['pct'] = self::pct($r['net'], $total);
            $r['avg_price'] = $r['qty'] ? (int) round($r['net'] / $r['qty']) : null;
            $c = $r['item_id'] !== null ? ($cost[(int) $r['item_id']] ?? null) : null;
            $r['unit_cost'] = $c;
            if ($c !== null) {
                $r['cost'] = (int) round($c * $r['qty']);
                $r['margin'] = $r['net'] - $r['cost'];
                $r['margin_pct'] = $r['net'] ? round($r['margin'] / $r['net'] * 100, 1) : null;
                $marginTotal += $r['margin'];
                $netWithCost += $r['net'];
            } else {
                $r['cost'] = $r['margin'] = $r['margin_pct'] = null;
            }
        }
        unset($r);

        $families = [];
        foreach ($rows as $r) {
            $k = $r['item_id'] === null ? 'Linhas sem artigo' : ($r['family'] ?: 'Sem família');
            $families[$k] ??= ['family' => $k, 'net' => 0, 'items' => 0, 'margin' => null, 'net_with_cost' => 0];
            $families[$k]['net'] += $r['net'];
            $families[$k]['items']++;
            if ($r['margin'] !== null) {
                $families[$k]['margin'] = ($families[$k]['margin'] ?? 0) + $r['margin'];
                $families[$k]['net_with_cost'] += $r['net'];
            }
        }
        $families = array_values($families);
        foreach ($families as &$fa) {
            $fa['pct'] = self::pct($fa['net'], $total);
            $fa['margin_pct'] = $fa['margin'] !== null && $fa['net_with_cost'] ? round($fa['margin'] / $fa['net_with_cost'] * 100, 1) : null;
            unset($fa['net_with_cost']);
        }
        unset($fa);
        usort($families, static fn($a, $b) => $b['net'] <=> $a['net']);

        $customers = $db->all(
            "SELECT d.third_party AS id, COALESCE(t.name, 'Consumidor final') AS name, t.nif,
                    COUNT(DISTINCT CASE WHEN d.type <> 'NC' THEN d.id END) AS docs, SUM($sign * l.net_cents) AS net, MAX(d.date) AS last_date
             $base GROUP BY d.third_party ORDER BY net DESC",
            $args
        );
        foreach ($customers as &$c) {
            $c['net'] = (int) $c['net'];
            $c['pct'] = self::pct($c['net'], $total);
            $c['avg_doc'] = $c['docs'] ? (int) round($c['net'] / $c['docs']) : null;
        }
        unset($c);

        $monthly = array_fill(0, 12, 0);
        foreach ($db->all("SELECT CAST(substr(d.date, 6, 2) AS INTEGER) AS m, SUM($sign * l.net_cents) AS net $base GROUP BY m", $args) as $m) {
            $monthly[(int) $m['m'] - 1] = (int) $m['net'];
        }
        // mesmo período do ano anterior (mesmos filtros)
        $prevArgs = $args;
        [$prevArgs[0], $prevArgs[1]] = self::range($year - 1, $from, $to);
        $prevMonthly = array_fill(0, 12, 0);
        foreach ($db->all("SELECT CAST(substr(d.date, 6, 2) AS INTEGER) AS m, SUM($sign * l.net_cents) AS net $base GROUP BY m", $prevArgs) as $m) {
            $prevMonthly[(int) $m['m'] - 1] = (int) $m['net'];
        }
        $prevTotal = (int) array_sum($prevMonthly);
        $docs = (int) $db->value("SELECT COUNT(DISTINCT d.id) $base AND d.type <> 'NC'", $args);
        $credit = (int) $db->value("SELECT COALESCE(SUM(l.net_cents), 0) $base AND d.type = 'NC'", $args);

        return [
            'year' => $year, 'from' => $from, 'to' => $to,
            'totals' => [
                'net' => $total, 'previous' => $prevTotal ?: null,
                'change' => $prevTotal ? round(($total - $prevTotal) / abs($prevTotal) * 100, 1) : null,
                'docs' => $docs, 'avg_doc' => $docs ? (int) round(($total + $credit) / $docs) : null, 'credit_notes' => $credit,
                'customers' => count(array_filter($customers, static fn($c) => $c['id'] !== null)),
                'margin' => $netWithCost ? $marginTotal : null,
                'margin_pct' => $netWithCost ? round($marginTotal / $netWithCost * 100, 1) : null,
                'cost_coverage_pct' => self::pct($netWithCost, $total),
            ],
            'monthly' => $monthly, 'monthly_previous' => $prevTotal ? $prevMonthly : null,
            'items' => $rows, 'families' => $families, 'customers' => $customers,
            'family_list' => $db->all('SELECT id, name FROM item_families ORDER BY name'),
        ];
    }

    /** Custo médio unitário (centavos, sem IVA) de cada artigo nas compras registadas até $until. */
    public static function itemCosts(Db $db, string $until): array
    {
        $out = [];
        $rows = $db->all(
            "SELECT l.item_id, SUM(l.net_cents) AS net, SUM(l.qty_milli) AS qty FROM purchase_doc_lines l JOIN purchase_docs d ON d.id = l.doc_id
             WHERE d.status = 'registado' AND d.type IN ('FC','FP') AND l.item_id IS NOT NULL AND d.date <= ? GROUP BY l.item_id",
            [$until]
        );
        foreach ($rows as $r) {
            if ((int) $r['qty'] > 0) {
                $out[(int) $r['item_id']] = (int) $r['net'] / ((int) $r['qty'] / 1000);
            }
        }
        return $out;
    }

    // ================================================================== compras

    /** Compras do período (FC + FP − NF, sem IVA): totais, série mensal, por fornecedor e por conta. */
    public static function purchases(Db $db, int $year, int $from = 1, int $to = 12): array
    {
        [$d1, $d2] = self::range($year, $from, $to);
        $sign = "CASE WHEN d.type = 'NF' THEN -1 ELSE 1 END";
        $base = "FROM purchase_doc_lines l JOIN purchase_docs d ON d.id = l.doc_id LEFT JOIN third_parties t ON t.id = d.third_party
                 WHERE d.status = 'registado' AND d.type IN ('FC','FP','NF') AND d.date BETWEEN ? AND ?";
        $args = [$d1, $d2];
        $suppliers = $db->all(
            "SELECT d.third_party AS id, COALESCE(t.name, 'Sem fornecedor') AS name, t.nif,
                    COUNT(DISTINCT CASE WHEN d.type <> 'NF' THEN d.id END) AS docs, SUM($sign * l.net_cents) AS net, SUM($sign * l.vat_cents) AS vat, MAX(d.date) AS last_date
             $base GROUP BY d.third_party ORDER BY net DESC",
            $args
        );
        $total = (int) array_sum(array_column($suppliers, 'net'));
        foreach ($suppliers as &$s) {
            $s['net'] = (int) $s['net'];
            $s['vat'] = (int) $s['vat'];
            $s['pct'] = self::pct($s['net'], $total);
        }
        unset($s);

        $accRows = $db->all("SELECT l.account, SUM($sign * l.net_cents) AS net $base GROUP BY l.account", $args);
        $names = [];
        foreach ($db->all('SELECT code, name FROM accounts') as $a) {
            $names[$a['code']] = $a['name'];
        }
        $groups = [];
        foreach ($accRows as $a) {
            $g = substr((string) $a['account'], 0, 2);
            $groups[$g] ??= ['code' => $g, 'name' => $names[$g] ?? $g, 'net' => 0, 'detail' => []];
            $groups[$g]['net'] += (int) $a['net'];
            $groups[$g]['detail'][] = ['code' => $a['account'], 'name' => $names[$a['account']] ?? '', 'net' => (int) $a['net'], 'pct' => self::pct((int) $a['net'], $total)];
        }
        $groups = array_values($groups);
        foreach ($groups as &$g) {
            $g['pct'] = self::pct($g['net'], $total);
            usort($g['detail'], static fn($a, $b) => $b['net'] <=> $a['net']);
        }
        unset($g);
        usort($groups, static fn($a, $b) => $b['net'] <=> $a['net']);

        $monthly = array_fill(0, 12, 0);
        foreach ($db->all("SELECT CAST(substr(d.date, 6, 2) AS INTEGER) AS m, SUM($sign * l.net_cents) AS net $base GROUP BY m", $args) as $m) {
            $monthly[(int) $m['m'] - 1] = (int) $m['net'];
        }
        $prev = self::range($year - 1, $from, $to);
        $prevTotal = (int) $db->value("SELECT COALESCE(SUM($sign * l.net_cents), 0) $base", $prev);
        $docs = (int) $db->value("SELECT COUNT(DISTINCT d.id) $base AND d.type <> 'NF'", $args);
        $investment = (int) $db->value("SELECT COALESCE(SUM($sign * l.net_cents), 0) $base AND (l.account LIKE '43%' OR l.account LIKE '44%')", $args);

        return [
            'year' => $year, 'from' => $from, 'to' => $to,
            'totals' => ['net' => $total, 'previous' => $prevTotal ?: null, 'change' => $prevTotal ? round(($total - $prevTotal) / abs($prevTotal) * 100, 1) : null,
                'docs' => $docs, 'suppliers' => count(array_filter($suppliers, static fn($s) => $s['id'] !== null)), 'investment' => $investment],
            'monthly' => $monthly, 'suppliers' => $suppliers, 'accounts' => $groups,
        ];
    }

    // ================================================================== prazos

    /**
     * Prazos reais de recebimento (clientes) e de pagamento (fornecedores) no período, a partir dos documentos.
     * Para cada valor liquidado: dias desde a fatura e atraso face ao vencimento. Faturas-recibo e despesas pagas contam 0 dias.
     * Também o que está em aberto no fim do período, com o atraso médio.
     */
    public static function terms(Db $db, int $year, int $from = 1, int $to = 12): array
    {
        [$d1, $d2] = self::range($year, $from, $to);
        $asOf = min($d2, date('Y-m-d'));
        $sales = $db->all(
            "SELECT i.third_party AS party, COALESCE(t.name, i.customer_name) AS name, s.amount_cents AS amount, i.date AS doc_date, i.due_date, r.date AS paid
             FROM sales_settlements s JOIN sales_docs r ON r.id = s.receipt_id JOIN sales_docs i ON i.id = s.invoice_id LEFT JOIN third_parties t ON t.id = i.third_party
             WHERE r.status = 'emitido' AND r.type = 'RC' AND r.date BETWEEN ? AND ?
             UNION ALL
             SELECT d.third_party, COALESCE(t.name, d.customer_name, 'Consumidor final'), d.total_cents, d.date, d.date, d.date
             FROM sales_docs d LEFT JOIN third_parties t ON t.id = d.third_party
             WHERE d.status = 'emitido' AND d.type IN ('FR','TV') AND d.date BETWEEN ? AND ?",
            [$d1, $d2, $d1, $d2]
        );
        $buys = $db->all(
            "SELECT i.third_party AS party, t.name, s.amount_cents AS amount, i.date AS doc_date, i.due_date, p.date AS paid
             FROM purchase_settlements s JOIN purchase_docs p ON p.id = s.payment_id JOIN purchase_docs i ON i.id = s.invoice_id LEFT JOIN third_parties t ON t.id = i.third_party
             WHERE p.status = 'registado' AND p.type = 'PG' AND p.date BETWEEN ? AND ?
             UNION ALL
             SELECT d.third_party, COALESCE(t.name, 'Sem fornecedor'), d.total_cents, d.date, d.date, d.date
             FROM purchase_docs d LEFT JOIN third_parties t ON t.id = d.third_party
             WHERE d.status = 'registado' AND d.type = 'FP' AND d.date BETWEEN ? AND ?",
            [$d1, $d2, $d1, $d2]
        );
        $openSales = array_filter(Sales::openDocs($db, null, $asOf), static fn($r) => $r['date'] <= $asOf);
        $openBuys = array_filter(Purchases::openDocs($db, null, $asOf), static fn($r) => $r['date'] <= $asOf);
        return [
            'year' => $year, 'from' => $from, 'to' => $to, 'as_of' => $asOf,
            'receivable' => self::summarize($sales, $openSales),
            'payable' => self::summarize($buys, $openBuys),
        ];
    }

    private static function days(string $a, string $b): int
    {
        return (int) round((strtotime($b) - strtotime($a)) / 86400);
    }

    /** Média ponderada de dias, atraso, percentagem a tempo e ranking por terceiro. */
    private static function summarize(array $paid, array $open): array
    {
        $sum = $wDays = $wLate = $onTime = 0;
        $parties = [];
        foreach ($paid as $p) {
            $amt = (int) $p['amount'];
            if ($amt <= 0) {
                continue;
            }
            $days = max(0, self::days($p['doc_date'], $p['paid']));
            $late = max(0, self::days($p['due_date'] ?: $p['doc_date'], $p['paid']));
            $sum += $amt;
            $wDays += $amt * $days;
            $wLate += $amt * $late;
            $onTime += $late === 0 ? $amt : 0;
            $k = $p['party'] ?? 0;
            $parties[$k] ??= ['id' => $p['party'], 'name' => $p['name'] ?: '—', 'amount' => 0, 'w_days' => 0, 'w_late' => 0, 'count' => 0];
            $parties[$k]['amount'] += $amt;
            $parties[$k]['w_days'] += $amt * $days;
            $parties[$k]['w_late'] += $amt * $late;
            $parties[$k]['count']++;
        }
        foreach ($parties as &$x) {
            $x['avg_days'] = (int) round($x['w_days'] / $x['amount']);
            $x['avg_late'] = (int) round($x['w_late'] / $x['amount']);
            unset($x['w_days'], $x['w_late']);
        }
        unset($x);
        $parties = array_values($parties);
        usort($parties, static fn($a, $b) => [$b['avg_late'], $b['amount']] <=> [$a['avg_late'], $a['amount']]);

        $openTotal = $openOverdue = $wOverdue = 0;
        foreach ($open as $o) {
            $amt = (int) $o['open_cents'];
            $openTotal += $amt;
            if ($o['days_overdue'] > 0) {
                $openOverdue += $amt;
                $wOverdue += $amt * $o['days_overdue'];
            }
        }
        return [
            'paid' => $sum, 'count' => count($paid),
            'avg_days' => $sum ? (int) round($wDays / $sum) : null,
            'avg_late' => $sum ? (int) round($wLate / $sum) : null,
            'on_time_pct' => $sum ? round($onTime / $sum * 100, 1) : null,
            'open' => $openTotal, 'overdue' => $openOverdue,
            'overdue_avg_days' => $openOverdue ? (int) round($wOverdue / $openOverdue) : null,
            'parties' => array_slice($parties, 0, 15),
        ];
    }

    // ================================================================== imobilizado (painel)

    /** Investimento do ano (bens adquiridos), depreciações do exercício e valor líquido; null se não houver bens. */
    public static function assets(Db $db, int $year): ?array
    {
        if (!(int) $db->value('SELECT COUNT(*) FROM fixed_assets')) {
            return null;
        }
        $list = Assets::list($db, ['year' => $year, 'status' => '']);
        $invest = (int) $db->value("SELECT COALESCE(SUM(cost_cents), 0) FROM fixed_assets WHERE substr(acquisition_date, 1, 4) = ?", [(string) $year]);
        $count = (int) $db->value("SELECT COUNT(*) FROM fixed_assets WHERE substr(acquisition_date, 1, 4) = ?", [(string) $year]);
        $costs = Stats::costs($db, $year)['total'];
        return [
            'investment' => $invest, 'acquired' => $count,
            'depreciation' => (int) $list['totals']['year'], 'pending' => (int) $list['pending'],
            'net' => (int) $list['totals']['net'], 'cost' => (int) $list['totals']['cost'],
            'depreciation_pct_costs' => $costs ? round($list['totals']['year'] / $costs * 100, 1) : null,
        ];
    }
}
