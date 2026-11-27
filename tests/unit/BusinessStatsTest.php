<?php
use Ideia\BusinessStats;
use Ideia\Ledger;
use Ideia\Purchases;
use Ideia\Sales;
use Ideia\Stats;
use Ideia\Assets;

return [
    'estatísticas de vendas: por artigo, família e cliente, notas de crédito, margem pelo custo médio das compras' => function () {
        [$db, $cli, $prod, $serv] = salesSetup();
        $fam = Sales::saveFamily($db, ['name' => 'Construção']);
        Sales::saveItem($db, ['id' => $prod['id'], 'name' => $prod['name'], 'kind' => 'P', 'price' => '850,00', 'vat_code' => 'NOR', 'family_id' => $fam['id']]);
        $ft = Sales::save($db, ['type' => 'FT', 'date' => '2025-03-10', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '10'], ['item_id' => $serv['id'], 'qty' => '1']]]);
        Sales::save($db, ['type' => 'FT', 'date' => '2025-04-02', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '5', 'price' => '900,00']]]);
        $nc = Sales::derive($db, (int) $ft['id'], 'NC', 'credit');
        Sales::save($db, ['id' => $nc['id'], 'date' => '2025-04-05', 'issue_reason' => '2', 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '2']]]);
        Sales::save($db, ['type' => 'OR', 'date' => '2025-04-06', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '100']]]);
        $sup = Ledger::saveThirdParty($db, ['name' => 'Cimentos SA', 'nif' => '200000055', 'kind' => 'F', 'account' => '221']);
        Purchases::save($db, ['type' => 'FC', 'date' => '2025-02-01', 'due_date' => '2025-03-01', 'third_party' => $sup['id'], 'supplier_doc' => 'C1', 'register' => true,
            'lines' => [['item_id' => $prod['id'], 'description' => 'Cimento', 'qty' => '10', 'price' => '500,00', 'account' => '311'], ['item_id' => $prod['id'], 'description' => 'Cimento', 'qty' => '10', 'price' => '600,00', 'account' => '311']]]);

        $s = BusinessStats::sales($db, 2025);
        $p = array_values(array_filter($s['items'], fn($i) => (int) $i['item_id'] === (int) $prod['id']))[0];
        same(13.0, (float) $p['qty'], '10 + 5 − 2 (nota de crédito); o orçamento não conta');
        same(850000 + 450000 - 170000, $p['net']);
        same(55000, (int) round($p['unit_cost']), 'custo médio (500 e 600)');
        same(1130000 - 13 * 55000, $p['margin']);
        same(200000 + 1130000, $s['totals']['net'], 'cimento + transporte');
        same(2, $s['totals']['docs'], 'faturas (sem NC nem orçamento)');
        same(170000, $s['totals']['credit_notes']);
        same(1130000, $s['monthly'][2] + $s['monthly'][3] - 200000, 'série mensal');
        same('Construção', $s['families'][0]['family']);
        same(1, count($s['customers']));
        same(1130000, BusinessStats::sales($db, 2025, 1, 12, ['family' => $fam['id']])['totals']['net'], 'filtro por família');
        same(200000, BusinessStats::sales($db, 2025, 1, 12, ['family' => '0'])['totals']['net'], 'sem família');
        same(850000 + 200000, BusinessStats::sales($db, 2025, 3, 3)['totals']['net'], 'só março');
    },
    'estatísticas de compras e prazos reais de recebimento e pagamento (também nos rácios)' => function () {
        [$db, $cli, $prod] = salesSetup();
        $ft = Sales::save($db, ['type' => 'FT', 'date' => '2025-05-01', 'due_date' => '2025-05-31', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '10']]]);
        $total = (int) Sales::get($db, (int) $ft['id'])['total_cents'];
        Sales::pay($db, (int) $ft['id'], ['date' => '2025-05-21', 'amount_cents' => intdiv($total, 2), 'payment_method' => 'NU']);   // 20 dias, a tempo
        Sales::pay($db, (int) $ft['id'], ['date' => '2025-06-30', 'amount_cents' => $total - intdiv($total, 2), 'payment_method' => 'NU']); // 60 dias, 30 de atraso
        $sup = Ledger::saveThirdParty($db, ['name' => 'Fornecedor Prazos', 'nif' => '200000066', 'kind' => 'F', 'account' => '221']);
        $fc = Purchases::save($db, ['type' => 'FC', 'date' => '2025-05-10', 'due_date' => '2025-06-09', 'third_party' => $sup['id'], 'supplier_doc' => 'P1', 'register' => true,
            'lines' => [['description' => 'Renda', 'price' => '10.000,00', 'account' => '6261', 'vat_code' => 'ISE']]]);
        Purchases::save($db, ['type' => 'FP', 'date' => '2025-05-12', 'third_party' => $sup['id'], 'supplier_doc' => 'VD1', 'payment_method' => 'NU', 'register' => true,
            'lines' => [['description' => 'Material', 'price' => '1.000,00', 'account' => '6268', 'vat_code' => 'ISE']]]);
        Purchases::pay($db, ['invoices' => [$fc['id']], 'date' => '2025-06-09', 'payment_method' => 'TB']); // 30 dias, a tempo

        $t = BusinessStats::terms($db, 2025);
        same(40, $t['receivable']['avg_days'], '(20 + 60) / 2');
        same(15, $t['receivable']['avg_late']);
        same(50.0, $t['receivable']['on_time_pct']);
        same((int) round((1000000 * 30 + 100000 * 0) / 1100000), $t['payable']['avg_days'], 'despesa paga conta 0 dias');
        same(100.0, $t['payable']['on_time_pct']);
        same(0, $t['receivable']['open']);
        $ratios = array_column(Stats::ratios($db, 2025)['ratios'], null, 'key');
        same(40.0, $ratios['pmr']['value'], 'o rácio usa o prazo real');
        check(str_contains($ratios['pmr']['formula'], 'recibo'));

        $p = BusinessStats::purchases($db, 2025);
        same(1100000, $p['totals']['net']);
        same(2, $p['totals']['docs']);
        same('62', $p['accounts'][0]['code']);
        same(1000000, $p['accounts'][0]['detail'][0]['net']);
        same(1100000, $p['suppliers'][0]['net']);
    },
    'painel: imobilizado (investimento e depreciações do ano) só quando há bens' => function () {
        $db = demoCompany();
        same(null, BusinessStats::assets($db, 2025));
        Assets::save($db, ['category' => 'informatica', 'description' => 'Portátil', 'acquisition_date' => '2025-07-01', 'cost' => '120.000,00']);
        $a = Stats::dashboard($db, 2025)['assets'];
        same(12000000, $a['investment']);
        same(1, $a['acquired']);
        same((int) round(12000000 * 0.3333 / 12 * 6), $a['depreciation'], 'seis meses a 33,33%');
        same(1, $a['pending']);
    },
];
