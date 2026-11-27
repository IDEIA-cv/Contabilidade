<?php
use Ideia\Inventory;
use Ideia\Ledger;
use Ideia\Purchases;
use Ideia\Closing;
use Ideia\Stats;
use Ideia\Reports;

return [
    'inventário: compras → inventário → CMVMC, apuramentos sucessivos, anular o último e fecho exige o apuramento' => function () {
        $db = demoCompany();
        $sup = Ledger::saveThirdParty($db, ['name' => 'Grossista', 'nif' => '200000044', 'kind' => 'F', 'account' => '221']);
        $buy = fn($date, $no, $price) => Purchases::save($db, ['type' => 'FC', 'date' => $date, 'due_date' => $date, 'third_party' => $sup['id'], 'supplier_doc' => $no, 'register' => true,
            'lines' => [['description' => 'Mercadoria', 'price' => $price, 'account' => '311', 'vat_code' => 'ISE']]]);
        $buy('2025-03-01', 'G1', '100.000,00');
        $st = Inventory::status($db, 2025);
        same(10000000, $st['kinds']['mercadorias']['purchases']);
        same(1, Stats::dashboard($db, 2025)['inventory_pending'] > 0 ? 1 : 0, 'painel avisa');
        throws(fn() => Closing::closeYear($db, 2025), 'apuramento do custo das mercadorias');
        throws(fn() => Inventory::preview($db, 2025, ['final_mercadorias' => '150.000,00']), 'maior do que o inventário inicial');
        throws(fn() => Inventory::preview($db, 2025, ['date' => '2024-12-31']), 'dentro do exercício');

        $e1 = Inventory::post($db, 2025, ['date' => '2025-06-30', 'final_mercadorias' => '30.000,00']);
        same(7000000, $e1['preview']['total_cost'], '0 + 100 000 − 30 000');
        $bal = fn($acc) => (int) $db->value("SELECT COALESCE(SUM(debit_cents - credit_cents), 0) FROM v_movements WHERE account = ? AND fiscal_year = 2025", [$acc]);
        same(0, $bal('311'));
        same(3000000, $bal('32'));
        same(7000000, $bal('611'));
        same(-7000000, Reports::incomeStatement($db, 2025, 1, 12)['lines'][4]['value'], 'DR mostra o CMVMC');
        throws(fn() => Inventory::post($db, 2025, ['date' => '2025-06-15']), 'nesta data ou depois');

        $buy('2025-09-01', 'G2', '50.000,00');
        $e2 = Inventory::post($db, 2025, ['final_mercadorias' => '20.000,00']);
        same(['initial' => 3000000, 'purchases' => 5000000, 'final' => 2000000, 'cost' => 6000000],
            array_intersect_key($e2['preview']['rows'][0], array_flip(['initial', 'purchases', 'final', 'cost'])));
        same('2025-12-31', $e2['preview']['date']);
        throws(fn() => Inventory::unpost($db, 2025, (int) $e1['id']), 'mais recente');
        Inventory::unpost($db, 2025, (int) $e2['id']);
        same(5000000, Inventory::status($db, 2025)['kinds']['mercadorias']['purchases'], 'compras voltam a estar por passar');
        Inventory::post($db, 2025, ['final_mercadorias' => '20.000,00']);
        same(0, Reports::balanceSheet($db, 2025, 12)['difference']);
        Closing::closeYear($db, 2025);
        Closing::openNextYear($db, 2025);
        same(2000000, Inventory::status($db, 2026)['kinds']['mercadorias']['stock'], 'inventário final passa para o ano seguinte');
        same(true, Ledger::verifyChain($db)['ok'] ?? true);
    },
];
