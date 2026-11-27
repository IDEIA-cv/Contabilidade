<?php
use Ideia\Companies;
use Ideia\Db;
use Ideia\Efatura;
use Ideia\EfaturaReceived;
use Ideia\Purchases;
use Ideia\Sales;

/** O fornecedor (efaturaSetup, NIF 200000001) emite para o cliente NIF 123456789, que é a empresa compradora. */
function receivedSetup(): array
{
    [$seller, $cli, $prod, $serv] = efaturaSetup();
    $ft = Sales::save($seller, ['type' => 'FT', 'date' => '2025-04-01', 'due_date' => '2025-05-01', 'third_party' => $cli, 'issue' => true,
        'lines' => [['item_id' => $prod['id'], 'qty' => '10'], ['item_id' => $serv['id']]]]);
    $nc = Sales::derive($seller, (int) $ft['id'], 'NC', 'credit');
    $nc = Sales::save($seller, ['id' => $nc['id'], 'date' => '2025-04-05', 'issue_reason' => '2', 'lines' => [['item_id' => $prod['id'], 'qty' => '1']], 'issue' => true]);
    $fr = Sales::save($seller, ['type' => 'FR', 'date' => '2025-04-10', 'third_party' => $cli, 'payment_method' => 'NU', 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
    $rc = Sales::pay($seller, (int) $ft['id'], ['date' => '2025-04-20', 'amount' => '1 000,00', 'payment_method' => 'TB']);
    $xml = [];
    foreach (['ft' => $ft, 'nc' => $nc, 'fr' => $fr, 'rc' => $rc] as $k => $d) {
        $xml[$k] = Efatura::buildXml($seller, (int) $d['id']);
        check(!Efatura::validate($xml[$k]), "XML $k válido");
    }
    $c = Companies::create(['name' => 'Cliente Praia ' . uniqid(), 'nif' => '123456789', 'year' => 2025]);
    return [$seller, Companies::open($c['id']), $xml, $ft, $nc, $fr];
}

function zipOf(array $files): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'z');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $n => $c) {
        $z->addFromString($n, $c);
    }
    $z->close();
    $out = file_get_contents($tmp);
    unlink($tmp);
    return $out;
}

return [
    'e-Fatura recebidos: leitura do DFE (tipo, IUD, fornecedor, linhas e totais)' => function () {
        [$seller, , $xml, $ft] = receivedSetup();
        $ft = Sales::get($seller, (int) $ft['id']);
        $d = EfaturaReceived::parse($xml['ft']);
        same('1', $d['type_code']);
        same('FC', $d['purchase_type']);
        same($ft['iud'], $d['iud']);
        same('200000001', $d['supplier']['nif']);
        same('123456789', $d['receiver_nif']);
        same(2, count($d['lines']));
        same((int) $ft['total_cents'], $d['total_cents']);
        same((int) $ft['vat_cents'], $d['vat_cents']);
        same('2025-05-01', $d['due_date']);
        throws(fn() => EfaturaReceived::parse('<Outro/>'), 'Dfe');
        throws(fn() => EfaturaReceived::files('x.txt', 'texto simples'), 'não é um XML');
    },
    'e-Fatura recebidos: importa FC, NF com origem e FP (ZIP); duplicados, recibos e vendas próprias ignorados' => function () {
        [$seller, $buyer, $xml, $ft] = receivedSetup();
        // na empresa emitente, os seus próprios documentos são vendas
        $p = EfaturaReceived::preview($seller, 'ft.xml', $xml['ft']);
        same('ignorado', $p['rows'][0]['status']);

        // NC antes da fatura de origem: erro explicativo
        same('erro', EfaturaReceived::preview($buyer, 'nc.xml', $xml['nc'])['rows'][0]['status']);

        $p = EfaturaReceived::preview($buyer, 'ft.xml', $xml['ft']);
        same(['novo' => 1, 'duplicado' => 0, 'ignorado' => 0, 'erro' => 0], $p['counts']);
        check(!$p['rows'][0]['supplier_exists'] && !isset($p['rows'][0]['data']), 'fornecedor novo, sem dados internos');
        same(0, (int) $buyer->value('SELECT COUNT(*) FROM purchase_docs'), 'a pré-visualização não grava');

        $r = EfaturaReceived::import($buyer, 'ft.xml', $xml['ft'], ['register' => '1']);
        same(1, $r['created']);
        $fc = Purchases::get($buyer, (int) $r['rows'][0]['created_id']);
        same('rascunho', $fc['status'], 'sem histórico do fornecedor fica em rascunho');
        same((int) Sales::get($seller, (int) $ft['id'])['total_cents'], (int) $fc['total_cents']);
        same('2025-05-01', $fc['due_date']);
        same('F', $buyer->value("SELECT kind FROM third_parties WHERE nif = '200000001'"), 'fornecedor criado');
        $fc = Purchases::register($buyer, (int) $fc['id']);

        same('duplicado', EfaturaReceived::preview($buyer, 'ft.xml', $xml['ft'])['rows'][0]['status']);

        $zip = zipOf(['nc.xml' => $xml['nc'], 'fr.xml' => $xml['fr'], 'rc.xml' => $xml['rc'], 'ft.xml' => $xml['ft']]);
        $r = EfaturaReceived::import($buyer, 'recebidos.zip', $zip, ['register' => '1']);
        same(2, $r['created']);
        $by = array_column($r['rows'], null, 'file');
        same('ignorado', $by['rc.xml']['status'], 'recibo sem efeito');
        same('duplicado', $by['ft.xml']['status']);
        $nf = Purchases::get($buyer, (int) $by['nc.xml']['created_id']);
        same(['NF', 'registado', (int) $fc['id']], [$nf['type'], $nf['status'], (int) $nf['origin_id']], 'nota de crédito ligada à fatura e registada');
        $fp = Purchases::get($buyer, (int) $by['fr.xml']['created_id']);
        same(['FP', 'registado', 'NU'], [$fp['type'], $fp['status'], $fp['payment_method']]);
        same(3, (int) $buyer->value('SELECT COUNT(*) FROM purchase_docs WHERE iud IS NOT NULL'));
        check((bool) $buyer->value("SELECT 1 FROM import_log WHERE source = 'e-Fatura (recebidos)'"), 'registo de importação');

        // conferência: compras da e-Fatura batem com o IVA dedutível da contabilidade
        $c = Efatura::conference($buyer, 2025);
        $apr = $c['rows'][3];
        same((int) $fc['vat_cents'] - (int) $nf['vat_cents'] + (int) $fp['vat_cents'], $apr['purchases_efatura']);
        same(0, $apr['purchases_diff'], 'sem diferenças em abril');
        same([], $c['manual_docs']);
    },
    'conferência do IVA: vendas por estado na e-Fatura contra o IVA liquidado' => function () {
        [$seller] = receivedSetup();
        $c = Efatura::conference($seller, 2025);
        $apr = $c['rows'][3];
        check($apr['sales_pending'] > 0, 'documentos por comunicar');
        same(0, $apr['sales_diff'], 'documentos = contabilidade em abril');
        same(1500000, $c['rows'][1]['sales_diff'], 'fevereiro: IVA lançado à mão, sem documento de venda');
        same(300000, $c['rows'][1]['purchases_diff'], 'fevereiro: IVA dedutível sem documento de compra');
        same(12, count($c['rows']));
        same(1800000, $c['totals']['sales_diff'] + $c['totals']['purchases_diff']);
        $seller->setMeta('efatura_since', '2025-03-01');
        $c = Efatura::conference($seller, 2025);
        check($c['rows'][1]['before_since'] && !$c['rows'][2]['before_since'], 'fevereiro é anterior à adesão');
        same(0, $c['totals']['sales_diff'], 'diferenças anteriores à adesão não contam');
    },
    'migração v8 → v9 acrescenta o IUD às compras' => function () {
        $db = demoCompany();
        $db->run('DROP INDEX IF EXISTS ux_purchase_iud');
        $db->run('ALTER TABLE purchase_docs DROP COLUMN iud');
        $db->setMeta('schema_version', '8');
        $db2 = new Db($db->path);
        same((string) Db::SCHEMA_VERSION, $db2->meta('schema_version'));
        check((bool) $db2->value("SELECT 1 FROM pragma_table_info('purchase_docs') WHERE name = 'iud'"));
        check((bool) $db2->value("SELECT 1 FROM sqlite_master WHERE name = 'ux_purchase_iud'"));
    },
];
