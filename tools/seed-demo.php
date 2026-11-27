<?php
declare(strict_types=1);

/**
 * Cria uma empresa de demonstração com dois exercícios de movimentos fictícios (para testar relatórios e estatísticas).
 * Uso: php tools/seed-demo.php [pasta_de_dados]
 */

require __DIR__ . '/../src/bootstrap.php';

use Ideia\Bank;
use Ideia\Closing;
use Ideia\Companies;
use Ideia\Config;
use Ideia\Ledger;
use Ideia\Stats;

if (isset($argv[1])) {
    Config::override(['data_dir' => $argv[1], 'backup_dir' => $argv[1] . '/backups']);
}
mt_srand(42);

$c = Companies::create(['name' => 'Empresa Demonstração Lda', 'nif' => '250000001', 'year' => 2024, 'address' => 'Avenida Cidade de Lisboa', 'city' => 'Praia']);
$db = Companies::open($c['id']);

$clients = [];
foreach ([['Hotel Atlântico SA', '251111111', 'Sal'], ['Supermercado Plateau Lda', '252222222', 'Praia'], ['Câmara Municipal Exemplo', '253333333', 'Mindelo'], ['Agência Morabeza', '254444444', 'Praia'], ['Clínica Sodade', '255555555', 'Assomada']] as [$n, $nif, $city]) {
    $clients[] = Ledger::saveThirdParty($db, ['name' => $n, 'nif' => $nif, 'kind' => 'C', 'city' => $city, 'account' => '211'])['id'];
}
$suppliers = [];
foreach ([['Electra SA', '261111111', '624'], ['CV Telecom', '262222222', '6262'], ['Imobiliária Achada', '263333333', '6261'], ['Papelaria Central', '264444444', '623'], ['Seguradora Garantia', '265555555', '6263']] as [$n, $nif, $acc]) {
    $suppliers[] = ['id' => Ledger::saveThirdParty($db, ['name' => $n, 'nif' => $nif, 'kind' => 'F', 'city' => 'Praia', 'account' => '221'])['id'], 'acc' => $acc];
}

$entry = static function (string $date, string $journal, string $desc, array $lines, ?string $doc = null) use ($db) {
    Ledger::saveEntry($db, ['date' => $date, 'journal' => $journal, 'description' => $desc, 'document' => $doc, 'lines' => $lines, 'validate' => true]);
};

$run = static function (int $year, float $growth) use ($entry, $clients, $suppliers, $db) {
    if ($year === 2024) {
        $entry("$year-01-02", 'BCO', 'Realização do capital social', [['account' => '121', 'debit_cents' => 300000000], ['account' => '51', 'credit_cents' => 300000000]]);
        $entry("$year-01-10", 'DIV', 'Aquisição de equipamento informático', [['account' => '435', 'debit_cents' => 45000000], ['account' => '2432', 'debit_cents' => 6750000, 'vat_code' => 'DED', 'vat_base_cents' => 45000000], ['account' => '121', 'credit_cents' => 51750000]]);
    }
    $season = [0.8, 0.85, 1.0, 1.05, 1.0, 1.1, 1.2, 1.25, 1.0, 0.95, 0.9, 1.3];
    $doc = 1;
    for ($m = 1; $m <= 12; $m++) {
        if ($year === 2025 && $m > 10) {
            break; // o ano corrente vai até outubro
        }
        $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        // vendas: 3 a 5 faturas por mês
        $n = 3 + ($m % 3);
        for ($i = 0; $i < $n; $i++) {
            $base = (int) round((mt_rand(150000, 600000) * $season[$m - 1] * $growth)) * 100;
            $iva = intdiv($base * 15, 100);
            $cli = $clients[mt_rand(0, count($clients) - 1)];
            $day = str_pad((string) mt_rand(1, 25), 2, '0', STR_PAD_LEFT);
            $entry("$year-$mm-$day", 'VEN', 'Prestação de serviços de consultoria', [
                ['account' => '211', 'debit_cents' => $base + $iva, 'third_party' => $cli],
                ['account' => '721', 'credit_cents' => $base],
                ['account' => '2433', 'credit_cents' => $iva, 'vat_code' => 'NOR', 'vat_base_cents' => $base],
            ], "FTE $year/" . $doc++);
            // recebimento de ~85% no mês seguinte (ou no próprio mês)
            if (mt_rand(1, 100) <= 85) {
                $pd = sprintf('%04d-%02d-%02d', $year, $m, min(28, (int) $day + mt_rand(1, 10)));
                $entry($pd, 'BCO', 'Recebimento de cliente', [['account' => '121', 'debit_cents' => $base + $iva], ['account' => '211', 'credit_cents' => $base + $iva, 'third_party' => $cli]]);
            }
        }
        // gastos fixos
        foreach ($suppliers as $k => $s) {
            $base = [3500000, 1200000, 8000000, 900000, 1500000][$k];
            $base = (int) round($base * (0.9 + mt_rand(0, 20) / 100));
            $iva = intdiv($base * 15, 100);
            $entry("$year-$mm-05", 'COM', 'Fatura de fornecedor', [
                ['account' => $s['acc'], 'debit_cents' => $base],
                ['account' => '2432', 'debit_cents' => $iva, 'vat_code' => 'DED', 'vat_base_cents' => $base],
                ['account' => '221', 'credit_cents' => $base + $iva, 'third_party' => $s['id']],
            ], "FF $year/$mm/$k");
            $entry("$year-$mm-20", 'BCO', 'Pagamento a fornecedor', [['account' => '221', 'debit_cents' => $base + $iva, 'third_party' => $s['id']], ['account' => '121', 'credit_cents' => $base + $iva]]);
        }
        // salários
        $sal = (int) round(60000000 * $growth);
        $inps = intdiv($sal * 16, 100);
        $entry("$year-$mm-28", 'SAL', 'Processamento de salários', [
            ['account' => '632', 'debit_cents' => $sal], ['account' => '635', 'debit_cents' => $inps],
            ['account' => '245', 'credit_cents' => $inps + intdiv($sal * 85, 1000)], ['account' => '242', 'credit_cents' => intdiv($sal * 10, 100)],
            ['account' => '231', 'credit_cents' => $sal - intdiv($sal * 85, 1000) - intdiv($sal * 10, 100)],
        ]);
        $entry("$year-$mm-28", 'BCO', 'Pagamento de salários', [['account' => '231', 'debit_cents' => $sal - intdiv($sal * 85, 1000) - intdiv($sal * 10, 100)], ['account' => '121', 'credit_cents' => $sal - intdiv($sal * 85, 1000) - intdiv($sal * 10, 100)]]);
        // depreciação mensal do equipamento (3 anos)
        $entry("$year-$mm-28", 'DIV', 'Depreciação do período', [['account' => '642', 'debit_cents' => 1250000], ['account' => '438', 'credit_cents' => 1250000]]);
    }
};

$run(2024, 1.0);
Closing::closeYear($db, 2024);
Closing::openNextYear($db, 2024);
$run(2025, 1.12);
Stats::saveBudget($db, 2025, '721', array_fill(0, 12, '1600000'));
Stats::saveBudget($db, 2025, '62', array_fill(0, 12, '160000'));
Stats::saveCustomIndicators($db, [
    ['name' => 'Margem de serviços', 'formula' => '(C(72) - D(62)) / C(72) * 100', 'unit' => '%'],
    ['name' => 'Peso dos salários nas vendas', 'formula' => 'D(63) / C(71,72) * 100', 'unit' => '%'],
]);
// um rascunho, para mostrar o alerta
Ledger::saveEntry($db, ['date' => '2025-10-30', 'journal' => 'DIV', 'description' => 'Rascunho por rever', 'lines' => [['account' => '6268', 'debit' => '5000'], ['account' => '111', 'credit' => '5000']]]);

// extrato bancário de setembro e outubro de 2025 (conta 121), para a reconciliação:
// datas do banco 0 a 2 dias depois, dois pagamentos de fim de outubro ainda não debitados, comissões e juros que faltam na contabilidade
$opening = (int) $db->value("SELECT COALESCE(SUM(debit_cents - credit_cents), 0) FROM v_movements WHERE account = '121' AND fiscal_year = 2025 AND date < '2025-09-01'");
$csv = "Banco Exemplo de Cabo Verde - Extrato de conta\nConta: 0001234567;Moeda: ECV\n\nData Mov.;Descritivo;Referência;Débito;Crédito;Saldo\n";
$bal = $opening;
$rows = $db->all("SELECT date, entry_description AS d, document, debit_cents - credit_cents AS v FROM v_movements WHERE account = '121' AND date BETWEEN '2025-09-01' AND '2025-10-31' AND journal_kind = 'normal' ORDER BY date, line_id");
$skip = array_slice(array_keys(array_filter($rows, static fn($r) => $r['v'] < 0)), -2); // dois pagamentos em circulação
$lines = [];
foreach ($rows as $k => $r) {
    if (in_array($k, $skip, true)) {
        continue;
    }
    $lines[] = [date('Y-m-d', strtotime($r['date'] . ' +' . ($k % 3) . ' days')), $r['d'], (string) $r['document'], (int) $r['v']];
}
$lines[] = ['2025-09-30', 'Comissão de manutenção de conta', '', -35000];
$lines[] = ['2025-10-31', 'Comissão de manutenção de conta', '', -35000];
$lines[] = ['2025-10-31', 'Juros credores', '', 12750];
usort($lines, static fn($a, $b) => strcmp($a[0], $b[0]));
foreach ($lines as [$d, $desc, $ref, $v]) {
    $bal += $v;
    $fmt = static fn(int $c) => number_format($c / 100, 2, ',', '.');
    $csv .= date('d/m/Y', strtotime($d)) . ";{$desc};{$ref};" . ($v < 0 ? $fmt(-$v) : '') . ';' . ($v > 0 ? $fmt($v) : '') . ';' . $fmt($bal) . "\n";
}
Bank::import($db, '121', $csv, true, 'extrato_set_out_2025.csv');

echo "Empresa de demonstração criada: {$c['id']} (" . $db->path . ")\n";
