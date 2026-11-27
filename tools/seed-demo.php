<?php
declare(strict_types=1);

/**
 * Cria uma empresa de demonstração com dois exercícios de movimentos fictícios (para testar relatórios e estatísticas).
 * Uso: php tools/seed-demo.php [pasta_de_dados]
 */

require __DIR__ . '/../src/bootstrap.php';

use Ideia\Assets;
use Ideia\Bank;
use Ideia\Closing;
use Ideia\Companies;
use Ideia\Config;
use Ideia\Efatura;
use Ideia\Ledger;
use Ideia\Purchases;
use Ideia\Sales;
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

// imobilizado: bens comprados em 2024 (depreciações de 2024 lançadas; as de 2025 ficam por lançar, para experimentar)
$entry('2024-02-12', 'DIV', 'Compra de computadores e impressora', [['account' => '435', 'debit' => '285000'], ['account' => '121', 'credit' => '285000']], 'FT 2024/118');
$entry('2024-04-02', 'DIV', 'Compra de viatura ligeira', [['account' => '434', 'debit' => '1850000'], ['account' => '121', 'credit' => '1850000']], 'FT A/2024/45');
$entry('2024-06-20', 'DIV', 'Mobiliário de escritório', [['account' => '435', 'debit' => '96000'], ['account' => '121', 'credit' => '96000']], 'FT 2024/377');
Assets::save($db, ['category' => 'informatica', 'description' => 'Computadores portáteis (3) e impressora', 'acquisition_date' => '2024-02-12', 'cost' => '285.000,00', 'third_party' => $suppliers[3]['id'], 'supplier_doc' => 'FT 2024/118', 'location' => 'Escritório da Praia']);
Assets::save($db, ['category' => 'viaturas', 'description' => 'Viatura ligeira de passageiros', 'acquisition_date' => '2024-04-02', 'cost' => '1.850.000,00', 'residual' => '150.000,00', 'supplier_doc' => 'FT A/2024/45', 'notes' => 'Matrícula ST-00-AA']);
Assets::save($db, ['category' => 'mobiliario', 'description' => 'Secretárias e cadeiras', 'acquisition_date' => '2024-06-20', 'start_date' => '2024-07-01', 'cost' => '96.000,00', 'location' => 'Escritório da Praia']);
Assets::post($db, 2024);
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


// vendas: artigos e documentos de novembro de 2025 (orçamento convertido em fatura, recibo parcial, fatura-recibo, guia, rascunho)
$db->setMeta('company_phone', '+238 260 00 00');
$db->setMeta('company_email', 'geral@demonstracao.cv');
$db->setMeta('company_bank', 'CV64 0001 0000 1234 5678 9101 2');
$db->setMeta('sales_footer', 'Capital social 3 000 000 ECV · Conservatória do Registo Comercial da Praia');
// e-Fatura em modo de teste (LED e código de software fictícios): os documentos fiscais recebem IUD e ficam na fila
Efatura::saveSettings($db, ['enabled' => '1', 'since' => '2025-11-01', 'repo' => '3', 'led' => '1', 'software_code' => 'IDEIADEMO']);
$famServ = Sales::saveFamily($db, ['name' => 'Consultoria e formação', 'kind' => 'S', 'account' => '721'])['id'];
$famProd = Sales::saveFamily($db, ['name' => 'Material e licenças', 'kind' => 'P', 'vat_code' => 'NOR', 'account' => '711'])['id'];
$it = [];
foreach ([
    ['S', 'Consultoria de gestão (hora)', 'H', '6.500,00', 'NOR', null],
    ['S', 'Formação em contabilidade (dia)', 'DIA', '45.000,00', 'ISE', '3'],
    ['P', 'Manual de procedimentos impresso', 'UN', '2.500,00', 'NOR', null],
    ['P', 'Licença de software anual', 'UN', '38.000,00', 'NOR', null],
    ['S', 'Deslocação fora da ilha', 'UN', '12.000,00', 'NOR', null],
] as [$k, $n, $u, $p, $v, $ex]) {
    $it[] = Sales::saveItem($db, ['kind' => $k, 'name' => $n, 'unit' => $u, 'price' => $p, 'vat_code' => $v, 'exemption' => $ex, 'family_id' => $k === 'S' ? $famServ : $famProd])['id'];
}
$or = Sales::save($db, ['type' => 'OR', 'date' => '2025-11-03', 'third_party' => $clients[0], 'issue' => true, 'reference' => 'Pedido por email', 'lines' => [
    ['item_id' => $it[0], 'qty' => '20'], ['item_id' => $it[4], 'qty' => '2'], ['item_id' => $it[2], 'qty' => '5', 'discount' => '10'],
]]);
$ft = Sales::derive($db, (int) $or['id'], 'FT');
$ft = Sales::save($db, ['id' => $ft['id'], 'date' => '2025-11-10', 'lines' => $ft['lines'], 'issue' => true]);
Sales::pay($db, (int) $ft['id'], ['date' => '2025-11-20', 'amount' => '100.000,00', 'payment_method' => 'NU']);
Sales::save($db, ['type' => 'FT', 'date' => '2025-11-12', 'third_party' => $clients[2], 'issue' => true, 'lines' => [['item_id' => $it[1], 'qty' => '3']]]);
Sales::save($db, ['type' => 'FT', 'date' => '2025-11-14', 'third_party' => $clients[1], 'issue' => true, 'lines' => [['item_id' => $it[3], 'qty' => '4']]]);
Sales::save($db, ['type' => 'TV', 'date' => '2025-11-15', 'payment_method' => 'NU', 'issue' => true, 'lines' => [['item_id' => $it[2], 'qty' => '2']]]);
Sales::save($db, ['type' => 'GE', 'date' => '2025-11-14', 'third_party' => $clients[1], 'issue' => true, 'delivery_place' => 'Armazém Achada Grande', 'lines' => [['item_id' => $it[3], 'qty' => '4']]]);
Sales::save($db, ['type' => 'FR', 'date' => '2025-11-17', 'third_party' => $clients[3], 'payment_method' => 'TB', 'issue' => true, 'lines' => [['item_id' => $it[0], 'qty' => '3']]]);
$ftLic = Sales::list($db, ['type' => 'FT', 'party' => $clients[1]])['rows'][0];
$nc = Sales::derive($db, (int) $ftLic['id'], 'NC', 'credit');
Sales::save($db, ['id' => $nc['id'], 'date' => '2025-11-21', 'issue_reason' => '2', 'notes' => 'Devolução de uma licença não utilizada',
    'lines' => [['item_id' => $it[3], 'qty' => '1']], 'issue' => true]);
Sales::save($db, ['type' => 'GT', 'date' => '2025-11-21', 'third_party' => $clients[0], 'issue' => true, 'delivery_place' => 'Hotel Atlântico, Santa Maria, Sal',
    'lines' => [['item_id' => $it[2], 'qty' => '10']]]);
Sales::save($db, ['type' => 'PF', 'date' => '2025-11-25', 'third_party' => $clients[3], 'lines' => [['item_id' => $it[0], 'qty' => '8']]]);

// mais documentos de exemplo (26/11 a 15/12/2025): faturas pagas, parcialmente pagas e vencidas, recibos, notas de débito
// e de crédito, pró-forma convertida, orçamentos em aberto, guia associada à fatura, cópia associada e uma fatura anulada
$doc = static fn(string $type, string $date, ?int $party, array $lines, array $extra = []) => Sales::save($db, ['type' => $type, 'date' => $date, 'third_party' => $party, 'issue' => true, 'lines' => $lines] + $extra);
$emit = static function (array $draft, string $date, array $extra = []) use ($db): array {
    return Sales::save($db, ['id' => $draft['id'], 'date' => $date, 'issue' => true] + $extra + ['lines' => $draft['lines']]);
};
$pf = $doc('PF', '2025-11-26', $clients[4], [['item_id' => $it[1], 'qty' => '2'], ['item_id' => $it[4]]], ['reference' => 'Formação da equipa administrativa']);
$ftPf = $emit(Sales::derive($db, (int) $pf['id'], 'FT'), '2025-11-28', ['due_date' => '2025-12-28']);
$ft4 = $doc('FT', '2025-11-28', $clients[2], [['item_id' => $it[0], 'qty' => '12'], ['item_id' => $it[2], 'qty' => '3']], ['due_date' => '2025-11-30', 'reference' => 'Requisição 2025/118']);
$ge = $doc('GE', '2025-11-28', $clients[2], [['item_id' => $it[2], 'qty' => '3']], ['delivery_place' => 'Paços do Concelho, Mindelo']);
Sales::link($db, (int) $ge['id'], (int) $ft4['id']); // guia associada à fatura
$nd = $emit(Sales::derive($db, (int) $ft4['id'], 'ND'), '2025-12-03', ['issue_reason' => 'DD', 'notes' => 'Despesas de deslocação ao Mindelo',
    'lines' => [['item_id' => $it[4], 'qty' => '1']]]);
$ft5 = $doc('FT', '2025-12-01', $clients[0], [['item_id' => $it[3], 'qty' => '2'], ['item_id' => $it[0], 'qty' => '6']], ['due_date' => '2025-12-10']);
$ft6 = $doc('FT', '2025-12-02', $clients[3], [['item_id' => $it[0], 'qty' => '4']]);
Sales::cancel($db, (int) $ft6['id'], 'Emitida com o cliente errado');
$ft7 = $doc('FT', '2025-12-02', $clients[1], [['item_id' => $it[0], 'qty' => '4']], ['due_date' => '2025-12-15']); // vencida, por receber
$nc2 = $emit(Sales::derive($db, (int) $ft5['id'], 'NC', 'credit'), '2025-12-10', ['issue_reason' => '2', 'notes' => 'Desconto comercial acordado',
    'lines' => [['description' => 'Desconto comercial sobre a fatura', 'qty' => '1', 'price' => '10.000,00', 'vat_code' => 'NOR']]]);
$doc('FR', '2025-12-04', $clients[4], [['item_id' => $it[2], 'qty' => '6']], ['payment_method' => 'CC']);
$doc('TV', '2025-12-05', null, [['item_id' => $it[2], 'qty' => '1']], ['payment_method' => 'NU']);
$doc('TV', '2025-12-11', null, [['item_id' => $it[2], 'qty' => '3']], ['payment_method' => 'CC']);
$orOpen = $doc('OR', '2025-12-08', $clients[1], [['item_id' => $it[3], 'qty' => '10', 'discount' => '5'], ['item_id' => $it[1]]], ['due_date' => '2026-12-31', 'reference' => 'Renovação das licenças 2026']);
$copy = Sales::derive($db, (int) $orOpen['id'], 'OR', 'copy', true); // cópia para outro cliente, associada ao original
$emit($copy, '2025-12-09', ['third_party' => $clients[4], 'due_date' => '2026-12-31', 'reference' => 'Proposta equivalente']);
$doc('GT', '2025-12-12', $clients[0], [['item_id' => $it[2], 'qty' => '20']], ['delivery_place' => 'Hotel Atlântico, Santa Maria, Sal']);
// recibos por ordem de data (a numeração segue as datas): parcial com cheque, total, total, segunda parte
Sales::pay($db, (int) $ft4['id'], ['date' => '2025-12-02', 'amount' => '40.000,00', 'payment_method' => 'CH']);
Sales::pay($db, (int) $ftPf['id'], ['date' => '2025-12-05', 'payment_method' => 'TB']);
Sales::pay($db, (int) $ft5['id'], ['date' => '2025-12-09', 'payment_method' => 'NU']);
Sales::pay($db, (int) $ft4['id'], ['date' => '2025-12-12', 'amount' => '25.000,00', 'payment_method' => 'TB']);
$ft8 = $doc('FT', '2025-12-15', $clients[4], [['item_id' => $it[1], 'qty' => '1'], ['item_id' => $it[0], 'qty' => '3']], ['due_date' => '2026-01-14']);

// compras de novembro: faturas de fornecedores, pagamento parcial, despesa paga e nota de crédito
$fc = static fn(int $s, string $no, string $date, string $due, array $lines) => Purchases::save($db, ['type' => 'FC', 'date' => $date, 'due_date' => $due,
    'third_party' => $suppliers[$s]['id'], 'supplier_doc' => $no, 'register' => true, 'lines' => $lines]);
$f1 = $fc(0, 'FT 2025/8812', '2025-11-05', '2025-11-30', [['description' => 'Eletricidade outubro', 'price' => '18.400,00', 'account' => '624']]);
$f2 = $fc(1, 'FAT 55120', '2025-11-06', '2025-11-21', [['description' => 'Internet e telefone outubro', 'price' => '9.800,00', 'account' => '6262']]);
$f3 = $fc(2, 'FR 2025/11', '2025-11-01', '2025-11-08', [['description' => 'Renda do escritório novembro', 'price' => '60.000,00', 'account' => '6261', 'vat_code' => 'ISE']]);
// compra de artigos que também se vendem (dá o custo médio e a margem nas estatísticas de vendas)
$fc(3, 'FT 2025/951', '2025-11-04', '2025-12-04', [
    ['item_id' => $it[2], 'description' => 'Manual de procedimentos impresso', 'qty' => '30', 'price' => '1.100,00', 'account' => '311'],
    ['item_id' => $it[3], 'description' => 'Licença de software anual', 'qty' => '5', 'price' => '24.500,00', 'account' => '311'],
]);
$f4 = $fc(4, 'AP-2025-301', '2025-11-18', '2026-01-17', [['description' => 'Seguro multirriscos (anual)', 'price' => '42.000,00', 'account' => '6263', 'vat_code' => 'ISE']]);
Purchases::pay($db, ['invoices' => [$f2['id']], 'date' => '2025-11-20', 'payment_method' => 'TB']);
Purchases::pay($db, ['invoices' => [$f3['id']], 'amount' => '30.000,00', 'date' => '2025-11-10', 'payment_method' => 'TB']);
Purchases::save($db, ['type' => 'FP', 'date' => '2025-11-19', 'third_party' => $suppliers[3]['id'], 'supplier_doc' => 'VD 7781', 'payment_method' => 'NU', 'register' => true,
    'lines' => [['description' => 'Papel e toner', 'price' => '4.350,00', 'account' => '6268']]]);
$nf = Purchases::creditFrom($db, (int) $f1['id']);
Purchases::save($db, ['id' => $nf['id'], 'date' => '2025-11-22', 'supplier_doc' => 'NC 2025/140', 'register' => true,
    'lines' => [['description' => 'Acerto de leitura', 'price' => '1.400,00', 'account' => '624']]]);

echo "Empresa de demonstração criada: {$c['id']} (" . $db->path . ")\n";
