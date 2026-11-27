<?php
declare(strict_types=1);

/**
 * Teste de desempenho: cria uma empresa com N lançamentos validados (por omissão 50 000) e mede as operações principais.
 * Uso: php tools/bench.php [n_lancamentos] [pasta_de_dados]
 */

require __DIR__ . '/../src/bootstrap.php';

use Ideia\Bank;
use Ideia\Companies;
use Ideia\Config;
use Ideia\Ledger;
use Ideia\Reports;
use Ideia\Saft\Exporter;
use Ideia\Stats;
use Ideia\Vat;

$n = (int) ($argv[1] ?? 50000);
$dir = $argv[2] ?? sys_get_temp_dir() . '/ideia-bench';
@mkdir($dir, 0775, true);
Config::override(['data_dir' => $dir, 'backup_dir' => $dir . '/backups', 'auto_backup' => false]);
mt_srand(7);

$t = microtime(true);
$c = Companies::create(['name' => 'Bench ' . $n . ' ' . date('His'), 'nif' => '299999999', 'year' => 2025]);
$db = Companies::open($c['id']);
$clients = [];
for ($i = 0; $i < 300; $i++) {
    $clients[] = Ledger::saveThirdParty($db, ['name' => "Cliente $i", 'nif' => (string) (210000000 + $i), 'kind' => 'C', 'account' => '211'])['id'];
}
$suppliers = [];
for ($i = 0; $i < 100; $i++) {
    $suppliers[] = Ledger::saveThirdParty($db, ['name' => "Fornecedor $i", 'nif' => (string) (260000000 + $i), 'kind' => 'F', 'account' => '221'])['id'];
}
$db->transaction(static function () use ($db, $n, $clients, $suppliers) {
    for ($i = 0; $i < $n; $i++) {
        $day = 1 + intdiv($i * 365, $n);
        $date = date('Y-m-d', strtotime("2025-01-01 +" . ($day - 1) . ' days'));
        $base = mt_rand(1000, 500000) * 100;
        $iva = intdiv($base * 15, 100);
        switch ($i % 4) {
            case 0:
                $cli = $clients[array_rand($clients)];
                $lines = [['account' => '211', 'debit_cents' => $base + $iva, 'third_party' => $cli], ['account' => '721', 'credit_cents' => $base], ['account' => '2433', 'credit_cents' => $iva, 'vat_code' => 'NOR', 'vat_base_cents' => $base]];
                $j = 'VEN';
                break;
            case 1:
                $cli = $clients[array_rand($clients)];
                $lines = [['account' => '121', 'debit_cents' => $base], ['account' => '211', 'credit_cents' => $base, 'third_party' => $cli]];
                $j = 'BCO';
                break;
            case 2:
                $sup = $suppliers[array_rand($suppliers)];
                $lines = [['account' => '624', 'debit_cents' => $base], ['account' => '2432', 'debit_cents' => $iva, 'vat_code' => 'DED', 'vat_base_cents' => $base], ['account' => '221', 'credit_cents' => $base + $iva, 'third_party' => $sup]];
                $j = 'COM';
                break;
            default:
                $sup = $suppliers[array_rand($suppliers)];
                $lines = [['account' => '221', 'debit_cents' => $base, 'third_party' => $sup], ['account' => '121', 'credit_cents' => $base]];
                $j = 'BCO';
        }
        Ledger::saveEntry($db, ['date' => $date, 'journal' => $j, 'description' => "Movimento $i", 'document' => "DOC $i", 'lines' => $lines, 'validate' => true]);
    }
});
$create = microtime(true) - $t;
printf("Empresa com %d lançamentos (%d linhas) criada em %.1f s (%.2f ms/lançamento). Ficheiro: %.1f MB\n\n", $n,
    (int) $db->value('SELECT COUNT(*) FROM entry_lines'), $create, $create * 1000 / $n, filesize($db->path) / 1048576);

$ops = [
    'Lançamentos (lista, 1.ª página)' => fn() => Ledger::listEntries($db, ['year' => 2025, 'limit' => 100]),
    'Lançamentos (pesquisa por conta 211)' => fn() => Ledger::listEntries($db, ['year' => 2025, 'account' => '211', 'limit' => 100]),
    'Balancete' => fn() => Reports::trialBalance($db, 2025, 1, 12),
    'Extrato da conta 121 (ano)' => fn() => Reports::ledger($db, '121', 2025, 1, 12),
    'Extrato da conta 2 (todas as filhas)' => fn() => Reports::ledger($db, '2', 2025, 1, 12),
    'Diário (mês)' => fn() => Reports::journal($db, 2025, 3, 3),
    'Balanço' => fn() => Reports::balanceSheet($db, 2025, 12),
    'DR' => fn() => Reports::incomeStatement($db, 2025, 1, 12),
    'Apuramento IVA (trimestre)' => fn() => Vat::settlement($db, 2025, 1, 3),
    'Painel' => fn() => Stats::dashboard($db, 2025),
    'Rácios' => fn() => Stats::ratios($db, 2025),
    'Gastos' => fn() => Stats::costs($db, 2025, 1, 12),
    'Maiores clientes' => fn() => Stats::topParties($db, 2025, 'C', 10),
    'Saldos em aberto e antiguidade' => fn() => Stats::openBalances($db, 2025, 'C'),
    'Previsão' => fn() => Stats::forecast($db, 2025),
    'Verificar cadeia de integridade' => fn() => Ledger::verifyChain($db),
    'Exportar SAF-T' => fn() => strlen(Exporter::export($db, 2025)),
    'Carteira (todas as empresas)' => fn() => Stats::portfolio(),
];
$results = [];
foreach ($ops as $name => $fn) {
    $t = microtime(true);
    $fn();
    $ms = (microtime(true) - $t) * 1000;
    $results[$name] = $ms;
    printf("%-42s %9.0f ms%s\n", $name, $ms, $ms > 1000 ? '  <-- lento' : '');
}
echo "\nFicheiro: {$db->path}\n";
