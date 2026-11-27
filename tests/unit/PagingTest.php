<?php
use Ideia\Ledger;
use Ideia\Reports;

return [
    'extrato e diário paginados coincidem com a versão completa' => function () {
        $db = demoCompany();
        for ($i = 1; $i <= 23; $i++) {
            Ledger::saveEntry($db, ['date' => sprintf('2025-04-%02d', min($i, 28)), 'journal' => 'CXA', 'description' => "Despesa $i", 'validate' => true,
                'lines' => [['account' => '6268', 'debit_cents' => $i * 100], ['account' => '121', 'credit_cents' => $i * 100]]]);
        }
        $full = Reports::ledger($db, '121', 2025);
        $rows = [];
        for ($off = 0; $off < $full['total_rows']; $off += 7) {
            $p = Reports::ledger($db, '121', 2025, 1, 12, 7, $off);
            same($full['closing'], $p['closing']);
            same($full['debit'], $p['debit']);
            $rows = array_merge($rows, $p['rows']);
        }
        same(array_column($full['rows'], 'balance'), array_column($rows, 'balance'), 'saldo acumulado contínuo entre páginas');
        $j = Reports::journal($db, 2025);
        $ids = [];
        for ($off = 0; $off < $j['total_entries']; $off += 5) {
            $p = Reports::journal($db, 2025, 1, 12, null, 5, $off);
            $ids = array_merge($ids, array_values(array_unique(array_column($p['rows'], 'entry_id'))));
            same($j['debit'], $p['debit']);
        }
        same(array_values(array_unique(array_column($j['rows'], 'entry_id'))), $ids, 'nenhum lançamento repetido ou perdido');
    },
];
