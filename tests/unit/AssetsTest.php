<?php
use Ideia\Assets;
use Ideia\Db;
use Ideia\Ledger;
use Ideia\Reports;

/** Saldo de uma conta no exercício (débito − crédito) a partir das linhas validadas. */
function balanceOf(Db $db, string $prefix, int $year): int
{
    return (int) $db->value("SELECT COALESCE(SUM(debit_cents - credit_cents), 0) FROM v_movements WHERE fiscal_year = ? AND account LIKE ?", [$year, $prefix . '%']);
}

return [
    'imobilizado: ficha, plano em duodécimos, lançar e anular depreciações do exercício' => function () {
        $db = demoCompany();
        Ledger::createFiscalYear($db, 2026);
        throws(fn() => Assets::save($db, ['category' => 'informatica', 'description' => 'Portátil', 'acquisition_date' => '2025-04-10', 'cost' => '0']), 'custo');
        throws(fn() => Assets::save($db, ['category' => 'informatica', 'description' => 'X', 'account' => '438', 'acquisition_date' => '2025-04-10', 'cost' => '100']), 'conta do bem');
        $pc = Assets::save($db, ['category' => 'informatica', 'description' => 'Portátil', 'acquisition_date' => '2025-04-10', 'cost' => '120.000,00']);
        same(['IM0001', '435', 3333], [$pc['code'], $pc['account'], (int) $pc['rate_bp']], 'categoria sugere conta e taxa');
        // 120 000 × 33,33% = 39 996/ano; 2025: abril a dezembro = 9 meses
        same([2025, 9, 2999700], [$pc['plan'][0]['year'], $pc['plan'][0]['months'], $pc['plan'][0]['amount']]);
        same(12000000, array_sum(array_column($pc['plan'], 'amount')), 'plano soma o custo');
        same(0, end($pc['plan'])['net']);
        same(4, count($pc['plan']), '2025 a 2028 (o último ano completa o valor)');
        $sw = Assets::save($db, ['category' => 'software', 'description' => 'Programa de faturação', 'acquisition_date' => '2025-07-01', 'cost' => '30.000,00', 'rate' => '50']);
        same(['443', 5000], [$sw['account'], (int) $sw['rate_bp']]);

        $p = Assets::preview($db, 2025);
        same(2, count($p['lines']));
        same(2999700 + 750000, $p['total']);
        $r = Assets::post($db, 2025);
        same(2, $r['count']);
        same(2999700, balanceOf($db, '642', 2025));
        same(750000, balanceOf($db, '643', 2025));
        same(-2999700, balanceOf($db, '438', 2025));
        throws(fn() => Assets::post($db, 2025), 'Não há depreciações por lançar');
        throws(fn() => Assets::save($db, ['id' => $pc['id'], 'category' => 'informatica', 'description' => 'Portátil', 'acquisition_date' => '2025-04-10', 'cost' => '130.000,00']), 'já tem depreciações lançadas');
        Assets::save($db, ['id' => $pc['id'], 'category' => 'informatica', 'description' => 'Portátil HP (contabilidade)', 'acquisition_date' => '2025-04-10', 'cost' => '120.000,00', 'location' => 'Escritório']);
        throws(fn() => Assets::delete($db, (int) $pc['id']), 'não pode ser apagado');

        $l = Assets::list($db, ['year' => 2026]);
        same(12000000 + 3000000, $l['totals']['cost']);
        same(2999700 + 750000, $l['totals']['accum_start']);
        same(3999600 + 1500000, $l['totals']['year'], '2026: ano completo');
        same(2, $l['pending']);

        Assets::unpost($db, 2025);
        same(0, balanceOf($db, '642', 2025), 'estornado');
        same(2, count(Assets::preview($db, 2025)['lines']), 'pode lançar de novo');
        throws(fn() => Assets::post($db, 2026), 'Lance primeiro as depreciações de 2025');
        Assets::post($db, 2025);
        Assets::post($db, 2026);
        throws(fn() => Assets::unpost($db, 2025), 'exercícios seguintes');
    },
    'imobilizado: venda com ganho, abate com perda e depreciações anteriores à aplicação' => function () {
        $db = demoCompany();
        Ledger::createFiscalYear($db, 2026);
        Ledger::saveEntry($db, ['date' => '2025-01-15', 'journal' => 'DIV', 'description' => 'Compra da viatura', 'validate' => true,
            'lines' => [['account' => '434', 'debit' => '2.000.000,00'], ['account' => '121', 'credit' => '2.000.000,00']]]);
        $car = Assets::save($db, ['category' => 'viaturas', 'description' => 'Viatura ligeira', 'acquisition_date' => '2025-01-15', 'cost' => '2.000.000,00']);
        Assets::post($db, 2025); // 25% × 12 meses = 500 000
        // venda em 30/06/2026 por 1 700 000: depreciação de 6 meses (250 000), acumuladas 750 000, líquido 1 250 000, ganho 450 000
        throws(fn() => Assets::dispose($db, (int) $car['id'], ['date' => '2026-06-30', 'value' => '1.700.000,00']), 'conta de movimento');
        $d = Assets::dispose($db, (int) $car['id'], ['date' => '2026-06-30', 'value' => '1.700.000,00', 'account' => '121']);
        same('abatido', $d['status']);
        same(45000000, $d['result_cents']);
        same(-45000000, balanceOf($db, '788', 2026));
        same(25000000, balanceOf($db, '642', 2026));
        same(0, balanceOf($db, '434', 2026) + balanceOf($db, '434', 2025), 'custo saiu');
        same(0, balanceOf($db, '438', 2026) + balanceOf($db, '438', 2025), 'acumuladas saíram');
        throws(fn() => Assets::dispose($db, (int) $car['id'], ['date' => '2026-07-01']), 'já foi abatido');
        same(0, Assets::list($db, ['year' => 2027])['totals']['cost'], 'não aparece depois do abate');

        // bem que já vinha de outro programa: 3 anos de depreciações acumuladas até 2024
        $m = Assets::save($db, ['category' => 'mobiliario', 'description' => 'Secretárias', 'acquisition_date' => '2022-01-01', 'cost' => '400.000,00',
            'prior' => '150.000,00', 'prior_year' => '2024']);
        same(2025, $m['plan'][0]['year']);
        same(5000000, $m['plan'][0]['amount'], '12,5% de 400 000');
        Assets::post($db, 2025);
        // abate sem valor em 2026-03-31: 3 meses (12 500), acumuladas 150 000 + 50 000 + 12 500, perda 187 500
        $d = Assets::dispose($db, (int) $m['id'], ['date' => '2026-03-31', 'reason' => 'Abate por avaria']);
        same(-18750000, $d['result_cents']);
        same(18750000, balanceOf($db, '688', 2026));
    },
    'migração v10 → v11: imobilizado e autor no registo de auditoria' => function () {
        $db = demoCompany();
        $db->run('DROP TABLE fixed_asset_depreciations');
        $db->run('DROP TABLE fixed_assets');
        $db->run('ALTER TABLE audit_log DROP COLUMN user');
        $db->setMeta('schema_version', '10');
        $db2 = new Db($db->path);
        same((string) Db::SCHEMA_VERSION, $db2->meta('schema_version'));
        check((bool) $db2->value("SELECT 1 FROM sqlite_master WHERE name = 'fixed_assets'"));
        check(in_array('user', array_column($db2->all('PRAGMA table_info(audit_log)'), 'name'), true));
    },
];
