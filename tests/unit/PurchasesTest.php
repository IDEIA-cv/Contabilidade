<?php
use Ideia\Purchases;
use Ideia\Reports;
use Ideia\Vat;
use Ideia\Ledger;

function purchasesSetup(): array
{
    $db = demoCompany();
    $sup = Ledger::saveThirdParty($db, ['name' => 'Fornecedor Mindelo', 'nif' => '254000111', 'kind' => 'F']);
    return [$db, (int) $sup['id']];
}

return [
    'fatura de fornecedor: validações, número interno, lançamento, IVA dedutível e conta sugerida' => function () {
        [$db, $sup] = purchasesSetup();
        throws(fn() => Purchases::save($db, ['type' => 'FC', 'date' => '2025-05-02', 'supplier_doc' => 'A1', 'lines' => [['description' => 'X', 'price' => '10']]]), 'Escolha o fornecedor');
        throws(fn() => Purchases::save($db, ['type' => 'FC', 'date' => '2025-05-02', 'third_party' => $sup, 'lines' => [['description' => 'X', 'price' => '10']]]), 'número do documento');
        throws(fn() => Purchases::save($db, ['type' => 'FC', 'date' => '2025-05-02', 'third_party' => $sup, 'supplier_doc' => 'A1', 'lines' => [['description' => 'X', 'price' => '10', 'account' => '711']]]), 'classes 3, 4 ou 6');
        throws(fn() => Purchases::save($db, ['type' => 'FC', 'date' => '2025-05-02', 'third_party' => $sup, 'supplier_doc' => 'A1', 'lines' => [['description' => 'X', 'price' => '10', 'account' => '626']]]), 'subcontas');
        throws(fn() => Purchases::save($db, ['type' => 'FC', 'date' => '2025-05-02', 'third_party' => $sup, 'supplier_doc' => 'A1', 'lines' => [['description' => 'X', 'price' => '10', 'vat_code' => 'NOR']]]), 'IVA de compras');
        $fc = Purchases::save($db, ['type' => 'FC', 'date' => '2025-05-02', 'third_party' => $sup, 'supplier_doc' => 'FT A/77', 'register' => true, 'lines' => [
            ['description' => 'Mercadoria', 'qty' => '10', 'price' => '1 000,00', 'account' => '311'],   // 10 000 + IVA 1 500
            ['description' => 'Frete', 'price' => '500,00', 'vat_code' => 'ISE', 'account' => '6268'],   // 500 isento
        ]]);
        same('FC 2025/1', $fc['doc_no']);
        same('registado', $fc['status']);
        same(1200000, (int) $fc['total_cents']);
        same('2025-06-01', $fc['due_date'], 'vencimento por omissão a 30 dias');
        same(1200000, (int) $fc['open_cents']);
        $e = $db->one('SELECT * FROM entries WHERE id = ?', [$fc['entry_id']]);
        same('COM', $e['journal']);
        same(1200000, (int) $db->value("SELECT credit_cents FROM entry_lines WHERE entry_id = ? AND account = '221'", [$fc['entry_id']]));
        $v = Vat::settlement($db, 2025, 5, 5);
        same(150000, $v['dedutivel']);
        throws(fn() => Purchases::save($db, ['type' => 'FC', 'date' => '2025-05-03', 'third_party' => $sup, 'supplier_doc' => 'FT A/77', 'lines' => [['description' => 'X', 'price' => '10']]]), 'já foi registado');
        same('6268', Purchases::lastAccount($db, $sup));
        $d = Purchases::save($db, ['type' => 'FC', 'date' => '2025-05-03', 'third_party' => $sup, 'supplier_doc' => 'FT A/78', 'lines' => [['description' => 'Outro', 'price' => '100']]]);
        same('6268', $d['lines'][0]['account'], 'conta sugerida = última do fornecedor');
        same('rascunho', $d['status']);
        Purchases::deleteDraft($db, (int) $d['id']);
        $tb = Reports::trialBalance($db, 2025);
        same($tb['totals']['debit'] ?? null, $tb['totals']['credit'] ?? null);
    },
    'pagamentos parciais e de várias faturas, nota de crédito, anulação e despesa paga' => function () {
        [$db, $sup] = purchasesSetup();
        $mk = fn($no, $date, $price) => Purchases::save($db, ['type' => 'FC', 'date' => $date, 'third_party' => $sup, 'supplier_doc' => $no, 'register' => true,
            'lines' => [['description' => 'Material', 'price' => $price, 'account' => '311']]]);
        $a = $mk('F1', '2025-05-01', '1 000,00');   // 1 150
        $b = $mk('F2', '2025-05-05', '2 000,00');   // 2 300
        $p1 = Purchases::pay($db, ['id' => $a['id'], 'amount' => '150,00', 'date' => '2025-05-10', 'payment_method' => 'TB']);
        same('PG 2025/1', $p1['doc_no']);
        same(100000, Purchases::openAmount($db, (int) $a['id']));
        throws(fn() => Purchases::pay($db, ['id' => $a['id'], 'amount' => '5 000,00', 'date' => '2025-05-10']), 'maior do que o valor em dívida');
        $p2 = Purchases::pay($db, ['invoices' => [$a['id'], $b['id']], 'date' => '2025-05-20', 'payment_method' => 'NU']);
        same(330000, (int) $p2['total_cents']);
        same(0, Purchases::openAmount($db, (int) $a['id']));
        same('CXA', $db->value('SELECT journal FROM entries WHERE id = ?', [$p2['entry_id']]));
        throws(fn() => Purchases::cancel($db, (int) $a['id'], 'erro'), 'Anule-os primeiro');
        Purchases::cancel($db, (int) $p2['id'], 'pagamento duplicado');
        same(230000, Purchases::openAmount($db, (int) $b['id']));
        // nota de crédito parcial a partir da fatura
        $nf = Purchases::creditFrom($db, (int) $b['id']);
        same('NF', $nf['type']);
        $nf = Purchases::save($db, ['id' => $nf['id'], 'date' => '2025-05-21', 'supplier_doc' => 'NC 9', 'register' => true,
            'lines' => [['description' => 'Devolução', 'price' => '200,00', 'account' => '311']]]);
        same(23000, (int) $nf['total_cents']);
        same(230000 - 23000, Purchases::openAmount($db, (int) $b['id']));
        same(23000, (int) $db->value("SELECT debit_cents FROM entry_lines WHERE entry_id = ? AND account = '221'", [$nf['entry_id']]));
        throws(fn() => Purchases::save($db, ['type' => 'NF', 'date' => '2025-05-21', 'origin_id' => $b['id'], 'supplier_doc' => 'NC 10',
            'lines' => [['description' => 'X', 'price' => '9 000,00', 'account' => '311']]]), 'por creditar');
        // despesa paga sem fornecedor, a caixa
        $fp = Purchases::save($db, ['type' => 'FP', 'date' => '2025-05-22', 'supplier_doc' => 'TAL 5', 'payment_method' => 'NU', 'register' => true,
            'lines' => [['description' => 'Combustível', 'price' => '1 000,00', 'account' => '624']]]);
        same(115000, (int) $db->value("SELECT credit_cents FROM entry_lines WHERE entry_id = ? AND account = '111'", [$fp['entry_id']]));
        // anular fatura sem pagamentos estorna o lançamento
        $c = $mk('F3', '2025-05-23', '100,00');
        $c = Purchases::cancel($db, (int) $c['id'], 'registada por engano');
        same('anulado', $c['status']);
        check((bool) $db->value('SELECT reversed_by FROM entries WHERE id = ?', [$c['entry_id']]), 'lançamento estornado');
        $tb = Reports::trialBalance($db, 2025);
        same($tb['totals']['debit'] ?? null, $tb['totals']['credit'] ?? null);
    },
    'dívidas a fornecedores por antiguidade, mapa de pagamentos, ficha e extrato' => function () {
        [$db, $sup] = purchasesSetup();
        $mk = fn($no, $date, $due, $price) => Purchases::save($db, ['type' => 'FC', 'date' => $date, 'due_date' => $due, 'third_party' => $sup, 'supplier_doc' => $no, 'register' => true,
            'lines' => [['description' => 'Material', 'price' => $price, 'account' => '311', 'vat_code' => 'ISE']]]);
        $mk('V1', '2025-01-10', '2025-02-10', '1 000,00');   // 95 dias em 16/05
        $mk('V2', '2025-04-01', '2025-05-01', '2 000,00');   // 15 dias
        $mk('V3', '2025-05-10', '2025-05-18', '3 000,00');   // a vencer, esta semana
        $mk('V4', '2025-05-10', '2025-07-30', '4 000,00');   // depois
        $c = Purchases::creditors($db, ['as_of' => '2025-05-16']);
        same(1, count($c['rows']));
        same(100000, $c['totals']['mais90']);
        same(200000, $c['totals']['d30']);
        same(700000, $c['totals']['a_vencer']);
        same(300000, $c['totals']['overdue']);
        $s = Purchases::schedule($db, '2025-05-16');   // sexta-feira: semana até domingo 18
        same(2, count($s['groups']['vencidas']));
        same(300000, $s['totals']['semana']);
        same(0, $s['totals']['mes']);
        same(400000, $s['totals']['depois']);
        $f = Purchases::supplier($db, $sup, 2025);
        same(1000000, $f['open']);
        same(4, count($f['open_docs']));
        $st = Reports::partyStatement($db, $sup, 2025, '2025-01-01', '2025-12-31');
        same(1000000, $st['closing'], 'extrato do fornecedor: saldo credor positivo');
        same(4, Purchases::list($db, ['pay' => 'aberto'])['total']);
        same(0, Purchases::list($db, ['pay' => 'pago'])['total']);
        same(1000000, Purchases::list($db, ['type' => 'FC'])['open']);
    },
    'migração v6 → v7 cria as tabelas de compras' => function () {
        [$db] = purchasesSetup();
        $db->run('DROP TABLE purchase_settlements');
        $db->run('DROP TABLE purchase_doc_lines');
        $db->run('DROP TABLE purchase_docs');
        $db->setMeta('schema_version', '6');
        $db2 = new \Ideia\Db($db->path);
        same((string) \Ideia\Db::SCHEMA_VERSION, $db2->meta('schema_version'));
        same(0, (int) $db2->value('SELECT COUNT(*) FROM purchase_docs'));
    },
];
