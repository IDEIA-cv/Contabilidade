<?php
use Ideia\Companies;
use Ideia\Ledger;
use Ideia\Reports;
use Ideia\Vat;
use Ideia\Closing;
use Ideia\Db;

return [
    'cria empresa com plano SNCRF e exercício' => function () {
        $db = demoCompany();
        check((int) $db->value('SELECT COUNT(*) FROM accounts') > 100, 'plano de contas carregado');
        same('21', $db->value("SELECT parent FROM accounts WHERE code = '211'"));
        check(Ledger::isMovementAccount($db, '211'));
        check(!Ledger::isMovementAccount($db, '21'), '21 tem subcontas');
    },
    'recusa lançamento desequilibrado e em conta de razão' => function () {
        $db = demoCompany();
        throws(fn() => Ledger::saveEntry($db, ['date' => '2025-04-01', 'description' => 'x', 'validate' => true, 'lines' => [
            ['account' => '111', 'debit' => '10'], ['account' => '721', 'credit' => '9'],
        ]]), 'não está equilibrado');
        throws(fn() => Ledger::saveEntry($db, ['date' => '2025-04-01', 'description' => 'x', 'validate' => true, 'lines' => [
            ['account' => '11', 'debit' => '10'], ['account' => '721', 'credit' => '10'],
        ]]), 'não é de movimento');
        throws(fn() => Ledger::saveEntry($db, ['date' => '2030-04-01', 'description' => 'x', 'lines' => []]), 'exercício');
    },
    'numeração sequencial por diário e cadeia de hash' => function () {
        $db = demoCompany();
        same([1, 2], array_map('intval', array_column($db->all("SELECT number FROM entries WHERE journal = 'BCO' ORDER BY number"), 'number')));
        $chain = Ledger::verifyChain($db);
        check($chain['ok'] && $chain['count'] === 4, 'cadeia íntegra');
        $db->run("UPDATE entry_lines SET debit_cents = debit_cents + 1 WHERE account = '624'");
        check(!Ledger::verifyChain($db)['ok'], 'adulteração detetada');
    },
    'lançamento validado é imutável e o estorno anula-o' => function () {
        $db = demoCompany();
        $id = (int) $db->value("SELECT id FROM entries WHERE journal = 'COM'");
        throws(fn() => Ledger::saveEntry($db, ['id' => $id, 'date' => '2025-02-15', 'description' => 'x', 'lines' => []]), 'não pode ser alterado');
        throws(fn() => Ledger::deleteDraft($db, $id));
        Ledger::reverseEntry($db, $id);
        $bal = Reports::leafBalances($db, 2025);
        same(0, $bal['624'] ?? 0);
        throws(fn() => Ledger::reverseEntry($db, $id), 'já foi estornado');
    },
    'balancete equilibrado e agregado por níveis' => function () {
        $db = demoCompany();
        $tb = Reports::trialBalance($db, 2025);
        same($tb['totals']['debit'], $tb['totals']['credit']);
        same($tb['totals']['balance_debit'], $tb['totals']['balance_credit']);
        $row = array_values(array_filter($tb['rows'], fn($r) => $r['code'] === '12'))[0];
        same(111500000, $row['balance_debit']);
    },
    'balanço fecha e DR calcula o resultado' => function () {
        $db = demoCompany();
        $dr = Reports::incomeStatement($db, 2025);
        same(8000000, $dr['resultado_liquido']);
        $bs = Reports::balanceSheet($db, 2025);
        same(0, $bs['difference'], 'ativo = capital próprio + passivo');
        same(111800000, $bs['total_ativo']);
    },
    'apuramento de IVA' => function () {
        $db = demoCompany();
        $v = Vat::settlement($db, 2025, 1, 3);
        same(1500000, $v['liquidado']);
        same(300000, $v['dedutivel']);
        same(1200000, $v['resultado']);
        same('a_pagar', $v['situacao']);
        Vat::postSettlement($db, 2025, 1, 3);
        $bal = Reports::leafBalances($db, 2025);
        same(-1200000, $bal['2436']);
        same(0, $bal['2433']);
    },
    'fecho do exercício e abertura do seguinte' => function () {
        $db = demoCompany();
        Closing::closeYear($db, 2025);
        $bal = Reports::leafBalances($db, 2025);
        same(0, $bal['721'] ?? 0);
        same(-8000000, $bal['818']);
        throws(fn() => Ledger::saveEntry($db, ['date' => '2025-06-01', 'description' => 'x', 'validate' => true, 'lines' => [
            ['account' => '111', 'debit' => '1'], ['account' => '721', 'credit' => '1'],
        ]]), 'fechado');
        $r = Closing::openNextYear($db, 2025);
        same(2026, $r['year']);
        $b26 = Reports::leafBalances($db, 2026);
        same(111500000, $b26['121']);
        same(-8000000, $b26['56']);
        $bs = Reports::balanceSheet($db, 2026);
        same(0, $bs['difference']);
        same(0, Reports::incomeStatement($db, 2026)['resultado_liquido']);
    },
    'cópia de segurança e restauro' => function () {
        $db = demoCompany('Backup');
        $id = basename($db->path, '.ideia');
        $file = Companies::backup($id);
        check(is_file($file));
        $r = Companies::restore($file, basename($file));
        check($r['id'] !== $id, 'restauro cria nova empresa');
        same(4, (int) Companies::open($r['id'])->value('SELECT COUNT(*) FROM entries'));
    },
];
