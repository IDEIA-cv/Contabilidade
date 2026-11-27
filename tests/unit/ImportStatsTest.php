<?php
use Ideia\Companies;
use Ideia\Ledger;
use Ideia\Reports;
use Ideia\Stats;
use Ideia\Saft\Exporter;
use Ideia\Saft\Importer;
use Ideia\Import\Csv;

return [
    'SAF-T: exporta e reimporta noutra empresa com os mesmos saldos' => function () {
        $src = demoCompany('Origem');
        $xml = Exporter::export($src, 2025);
        check(str_contains($xml, '<GeneralLedgerEntries>'));
        $dst = Companies::open(Companies::create(['name' => 'Destino ' . uniqid(), 'nif' => '200000001', 'year' => 2025])['id']);
        $pre = Importer::preview($dst, $xml);
        check($pre['can_import'], 'pode importar: ' . implode(' ', $pre['warnings']));
        same(4, $pre['transactions']);
        $sum = Importer::commit($dst, $xml, ['create_opening' => false]);
        same(4, $sum['entries']);
        $a = Reports::trialBalance($src, 2025)['totals'];
        $b = Reports::trialBalance($dst, 2025)['totals'];
        same($a, $b);
        same(1, (int) $dst->value("SELECT COUNT(*) FROM third_parties WHERE nif = '123456789'"));
        // segunda importação no mesmo exercício é recusada
        check(!Importer::preview($dst, $xml)['can_import']);
    },
    'SAF-T de outro software: contas novas, abertura e mapeamento de conflitos' => function () {
        $xml = file_get_contents(__DIR__ . '/../fixtures/saft-primavera-exemplo.xml');
        $db = Companies::open(Companies::create(['name' => 'Migra ' . uniqid(), 'year' => 2024])['id']);
        $pre = Importer::preview($db, $xml);
        check($pre['suggest_opening'], 'sugere abertura');
        same(['62'], array_column($pre['conflicts'], 'code'));
        check(in_array('1211', array_column($pre['new_accounts'], 'code'), true));
        throws(fn() => Importer::commit($db, $xml, ['create_opening' => true]), 'conta de destino');
        $sum = Importer::commit($db, $xml, ['create_opening' => true, 'map' => ['62' => '6268']]);
        same(3, $sum['entries']); // abertura + 2 transações
        $bal = Reports::leafBalances($db, 2024);
        same(25000000 + 1000000 - 500000, $bal['1211']);
        same(500000, $bal['6268']);
        same(0, Reports::balanceSheet($db, 2024)['difference']);
    },
    'CSV: modelos, contas, terceiros, saldos e lançamentos' => function () {
        $db = Companies::open(Companies::create(['name' => 'CSV ' . uniqid(), 'year' => 2025])['id']);
        check(str_contains(Csv::template('lancamentos'), 'codigo_iva'));
        $r = Csv::import($db, 'contas', "codigo;nome\n2111;Clientes nacionais\n", true);
        same(true, $r['committed']);
        $r = Csv::import($db, 'terceiros', "NIF;Nome;Tipo\n123456789;Cliente A;C\n", true);
        same(1, $r['imported']);
        $r = Csv::import($db, 'saldos', "conta;debito;credito\n121;1.000,00;\n51;;1.000,00\n", true, ['year' => 2025]);
        same(true, $r['committed']);
        $csv = "data;diario;documento;descricao;conta;debito;credito;nif\n"
             . "15/01/2025;VEN;F1;Venda;2111;115,00;;123456789\n"
             . "15/01/2025;VEN;F1;Venda;721;;100,00;\n"
             . "15/01/2025;VEN;F1;Venda;2433;;15,00;\n"
             . "16/01/2025;VEN;F2;Má;2111;10,00;;\n";
        $prev = Csv::import($db, 'lancamentos', $csv, false);
        same(1, count($prev['errors']), 'F2 desequilibrado');
        $r = Csv::import($db, 'lancamentos', $csv, true);
        same(false, $r['committed'], 'com erros nada é gravado');
        same(1, (int) $db->value('SELECT COUNT(*) FROM entries'));
        $ok = Csv::import($db, 'lancamentos', implode("\n", array_slice(explode("\n", $csv), 0, 4)), true);
        same(true, $ok['committed']);
        same(-10000, Reports::leafBalances($db, 2025)['721']);
        check(Csv::import($db, 'contas', mb_convert_encoding("codigo;nome\n2112;Clientes estrangeiros ção\n", 'Windows-1252', 'UTF-8'), true)['committed']);
        same('Clientes estrangeiros ção', $db->value("SELECT name FROM accounts WHERE code = '2112'"));
    },
    'estatísticas: painel, rácios, gastos, clientes, saldos e previsão' => function () {
        $db = demoCompany('Stats');
        $d = Stats::dashboard($db, 2025);
        same(3, $d['to']);
        same(10000000, $d['kpis'][0]['value']);
        same(8000000, $d['kpis'][3]['value']);
        same(111500000, $d['kpis'][4]['value']);
        $r = Stats::ratios($db, 2025);
        $byKey = array_column($r['ratios'], 'value', 'key');
        check($byKey['liq_geral'] > 1);
        same(round(108000000 / 111800000 * 100, 1), $byKey['autonomia']);
        $c = Stats::costs($db, 2025);
        same(2000000, $c['total']);
        same('62', $c['groups'][0]['code']);
        $top = Stats::topParties($db, 2025, 'C');
        same('Cliente Praia', $top['rows'][0]['name']);
        same(100.0, $top['rows'][0]['pct']);
        same(0, Stats::openBalances($db, 2025, 'C')['total']);
        $f = Stats::forecast($db, 2025);
        same(40000000, $f['items']['vendas']['projection']);
    },
    'estatísticas: orçamento contra real e indicadores personalizados' => function () {
        $db = demoCompany('Budget');
        Stats::saveBudget($db, 2025, '721', array_fill(0, 12, '50000'));
        $b = Stats::budgetVsActual($db, 2025, 3);
        same(15000000, $b['rows'][0]['budget_ytd']);
        same(10000000, $b['rows'][0]['actual_ytd']);
        check($b['rows'][0]['unfavorable']);
        same(80000.0, Stats::evaluate($db, 'C(7) - D(6)', 2025));
        same(0.2, Stats::evaluate($db, 'D(62) / C(72)', 2025));
        throws(fn() => Stats::evaluate($db, 'system("ls")', 2025), 'inválida');
        Stats::saveCustomIndicators($db, [['name' => 'Margem', 'formula' => '(C(72)-D(62))/C(72)*100', 'unit' => '%']]);
        same(80.0, Stats::customIndicators($db, 2025)[0]['value']);
    },
    'multiempresa: carteira com alertas' => function () {
        demoCompany('Carteira');
        $p = Stats::portfolio();
        check(count($p) >= 1);
        check(in_array('Sem cópia de segurança nos últimos 7 dias', $p[0]['alerts'], true));
    },
];
