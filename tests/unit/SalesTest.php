<?php
use Ideia\Sales;
use Ideia\SalesPrint;
use Ideia\Reports;
use Ideia\Vat;
use Ideia\Ledger;

function salesSetup(): array
{
    $db = demoCompany();
    $cli = (int) $db->value("SELECT id FROM third_parties WHERE name = 'Cliente Praia'");
    $prod = Sales::saveItem($db, ['name' => 'Cimento 50 kg', 'kind' => 'P', 'price' => '850,00', 'vat_code' => 'NOR']);
    $serv = Sales::saveItem($db, ['name' => 'Transporte', 'kind' => 'S', 'price' => '2 000,00', 'vat_code' => 'ISE', 'exemption' => Sales::EXEMPTIONS[0]]);
    return [$db, $cli, $prod, $serv];
}

return [
    'artigos: código automático, isenção obrigatória e inativar quando já usado' => function () {
        [$db, $cli, $prod, $serv] = salesSetup();
        same('P0001', $prod['code']);
        same('S0001', $serv['code']);
        same(85000, (int) $prod['price_cents']);
        same('721', $serv['account'], 'serviços vão para 72');
        throws(fn() => Sales::saveItem($db, ['name' => 'X', 'vat_code' => 'ISE']), 'motivo de isenção');
        throws(fn() => Sales::saveItem($db, ['name' => 'Y', 'code' => 'P0001']), 'Já existe');
        throws(fn() => Sales::saveItem($db, ['name' => 'Z', 'vat_code' => 'DED']), 'IVA de vendas');
        Sales::save($db, ['type' => 'OR', 'date' => '2025-04-01', 'third_party' => $cli, 'lines' => [['item_id' => $prod['id'], 'qty' => '1']]]);
        same('inativo', Sales::deleteItem($db, (int) $prod['id']));
        $tmp = Sales::saveItem($db, ['name' => 'Temporário']);
        same('apagado', Sales::deleteItem($db, (int) $tmp['id']));
    },
    'fatura: cálculo, numeração por tipo e série, lançamento e IVA' => function () {
        [$db, $cli, $prod, $serv] = salesSetup();
        $ft = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-02', 'third_party' => $cli, 'issue' => true, 'lines' => [
            ['item_id' => $prod['id'], 'qty' => '10', 'discount' => '10'],   // 8500 - 850 = 7650,00 + IVA 1147,50
            ['item_id' => $serv['id'], 'qty' => '1'],                         // 2000,00 isento
        ]]);
        same('FT 2025/1', $ft['doc_no']);
        same(965000, (int) $ft['net_cents']);
        same(85000, (int) $ft['discount_cents']);
        same(114750, (int) $ft['vat_cents']);
        same(1079750, (int) $ft['total_cents']);
        same('2025-05-02', $ft['due_date'], 'vencimento a 30 dias');
        same('pendente', $ft['efatura_status']);
        same('Cliente Praia', $ft['customer_name'], 'dados do cliente ficam no documento');
        same(1079750, (int) $ft['open_cents']);
        $e = Ledger::getEntry($db, (int) $ft['entry_id']);
        same('validado', $e['status']);
        same('VEN', $e['journal']);
        same(1079750, (int) $e['total_cents']);
        // outro tipo começa no 1; mesma série continua
        $or = Sales::save($db, ['type' => 'OR', 'date' => '2025-04-02', 'third_party' => $cli, 'issue' => true, 'lines' => [['description' => 'Serviço avulso', 'price' => '100', 'vat_code' => 'NOR']]]);
        same('OR 2025/1', $or['doc_no']);
        same(null, $or['entry_id'], 'orçamento não gera lançamento');
        $ft2 = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-03', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        same('FT 2025/2', $ft2['doc_no']);
        throws(fn() => Sales::save($db, ['type' => 'FT', 'date' => '2025-04-01', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]), 'ordem das datas');
        throws(fn() => Sales::save($db, ['type' => 'FT', 'date' => '2025-04-05', 'lines' => [['item_id' => $prod['id']]]]), 'Escolha o cliente');
        throws(fn() => Sales::save($db, ['id' => $ft['id'], 'date' => '2025-04-05', 'lines' => []]), 'não pode ser alterado');
        // IVA liquidado e base no apuramento
        $v = Vat::settlement($db, 2025, 4, 4);
        same(114750 + 12750, $v['liquidado']);
        // balancete continua equilibrado
        $tb = Reports::trialBalance($db, 2025);
        same($tb['totals']['debit'] ?? null, $tb['totals']['credit'] ?? null);
        same(true, Sales::verifyChain($db)['ok']);
        $html = SalesPrint::html($db, (int) $ft['id']);
        check(str_contains($html, 'FT 2025/1') && str_contains($html, '10 797,50'), 'impressão com número e total');
    },
    'recibo parcial, nota de crédito e anulação' => function () {
        [$db, $cli, $prod] = salesSetup();
        $ft = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-02', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '2']]]);
        same(195500, (int) $ft['total_cents']);
        $rc = Sales::pay($db, (int) $ft['id'], ['date' => '2025-04-10', 'amount' => '1.000,00', 'payment_method' => 'TB']);
        same('RC 2025/1', $rc['doc_no']);
        same('BCO', Ledger::getEntry($db, (int) $rc['entry_id'])['journal']);
        same(95500, Sales::openAmount($db, (int) $ft['id']));
        throws(fn() => Sales::pay($db, (int) $ft['id'], ['date' => '2025-04-11', 'amount' => '5 000,00']), 'maior do que o valor em dívida');
        $open = Sales::list($db, ['pay' => 'aberto']);
        same(1, $open['total']);
        same(95500, $open['open']);
        // nota de crédito de 1 unidade
        $nc = Sales::derive($db, (int) $ft['id'], 'NC', 'credit');
        same('rascunho', $nc['status']);
        $lines = $nc['lines'];
        $lines[0]['qty_milli'] = 1000;
        $nc = Sales::save($db, ['id' => $nc['id'], 'date' => '2025-04-12', 'origin_id' => $ft['id'], 'lines' => $lines, 'issue' => true]);
        same(97750, (int) $nc['total_cents']);
        same(-2250, Sales::openAmount($db, (int) $ft['id']), 'crédito a favor do cliente');
        throws(fn() => Sales::cancel($db, (int) $ft['id'], 'erro'), 'Anule-os primeiro');
        Sales::cancel($db, (int) $nc['id'], 'Devolução não aconteceu');
        Sales::cancel($db, (int) $rc['id'], 'Transferência devolvida');
        $c = Sales::cancel($db, (int) $ft['id'], 'Cliente errado');
        same('anulado', $c['status']);
        $e = Ledger::getEntry($db, (int) $ft['entry_id']);
        check($e['reversed_by'] !== null, 'lançamento estornado');
        // a numeração continua sem reaproveitar números
        $ft2 = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-12', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        same('FT 2025/2', $ft2['doc_no']);
    },
    'conversão orçamento → fatura e fatura-recibo a consumidor final' => function () {
        [$db, $cli, $prod, $serv] = salesSetup();
        $or = Sales::save($db, ['type' => 'OR', 'date' => '2025-04-01', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $serv['id'], 'qty' => '3']]]);
        $ft = Sales::derive($db, (int) $or['id'], 'FT');
        same('FT', $ft['type']);
        same((int) $or['id'], (int) $ft['origin_id']);
        same(600000, (int) $ft['total_cents']);
        throws(fn() => Sales::derive($db, (int) $or['id'], 'NC', 'convert'), 'converter');
        $fr = Sales::save($db, ['type' => 'FR', 'date' => '2025-04-02', 'payment_method' => 'NU', 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        same('FR 2025/1', $fr['doc_no']);
        same('Consumidor final', $fr['customer_name'] ?? 'Consumidor final');
        $e = Ledger::getEntry($db, (int) $fr['entry_id']);
        same('111', $e['lines'][0]['account'], 'fatura-recibo em numerário entra em caixa');
        $s = Sales::summary($db, 2025);
        same(97750, $s['invoiced']);
        same(1, $s['drafts']);
        same(1, Sales::list($db, ['q' => 'Cliente Praia', 'type' => 'OR'])['total']);
    },
    'dívidas por cliente com antiguidade e extrato de conta corrente' => function () {
        [$db, $cli, $prod] = salesSetup();
        $outro = Ledger::saveThirdParty($db, ['name' => 'Cliente Mindelo', 'nif' => '987654321', 'kind' => 'C'])['id'];
        $a = Sales::save($db, ['type' => 'FT', 'date' => '2025-01-10', 'due_date' => '2025-01-10', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);   // 977,50
        Sales::save($db, ['type' => 'FT', 'date' => '2025-03-01', 'due_date' => '2025-03-31', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '2']]]); // 1955,00
        Sales::save($db, ['type' => 'FT', 'date' => '2025-03-05', 'third_party' => $outro, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        Sales::pay($db, (int) $a['id'], ['date' => '2025-02-01', 'amount' => '500,00']);
        $d = Sales::debtors($db, ['as_of' => '2025-04-15']);
        same(2, count($d['rows']));
        $r = $d['rows'][0];
        same('Cliente Praia', $r['customer'], 'ordenado por valor vencido');
        same(47750 + 195500, $r['open']);
        same(47750, $r['mais90'], '95 dias depois do vencimento');
        same(195500, $r['d30']);
        same(97750, $d['rows'][1]['d30'], 'vencida a 04/04 (30 dias), 11 dias de atraso');
        same(1, count(Sales::debtors($db, ['as_of' => '2025-04-15', 'q' => 'mindelo'])['rows']));
        same(97750, Sales::debtors($db, ['as_of' => '2025-03-10'])['rows'][1]['a_vencer'] ?? Sales::debtors($db, ['as_of' => '2025-03-10'])['rows'][0]['a_vencer']);
        $st = Sales::statement($db, $cli, '2025-02-01', '2025-12-31');
        same(97750, $st['opening'], 'fatura de janeiro no saldo anterior');
        same(2, count($st['rows']));
        same(-50000, (int) $st['rows'][0]['amount'], 'recibo a crédito');
        same(97750 - 50000 + 195500, $st['closing']);
        $c = Sales::customer($db, $cli, 2025);
        same($st['closing'], $c['open'], 'saldo do extrato = dívida');
        $html = Ideia\SalesPrint::statement($db, $cli, '2025-01-01', '2025-12-31');
        check(str_contains($html, 'Faturas por pagar') && str_contains($html, '2 432,50'), 'extrato impresso com dívida');
    },
];
