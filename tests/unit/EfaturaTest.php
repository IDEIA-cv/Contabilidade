<?php
use Ideia\Efatura;
use Ideia\Ledger;
use Ideia\Sales;
use Ideia\SalesPrint;

return [
    'IUD: dígito de controlo (Luhn) do exemplo do manual e composição' => function () {
        $sample = 'CV1200520123456789000112345678901112345678904';
        check(Efatura::validIud($sample), 'IUD do manual válido');
        check(!Efatura::validIud(substr($sample, 0, 44) . '5'), 'dígito errado rejeitado');
        $iud = Efatura::makeIud('3', '2025-11-21', '250000001', '1', 7, 2, '0000012345');
        same(45, strlen($iud));
        same('CV3' . '251121' . '250000001' . '00001' . '07' . '000000002' . '0000012345', substr($iud, 0, 44), 'composição');
        check(Efatura::validIud($iud));
        same('https://pe.efatura.cv/dfe/view/' . $iud, Efatura::qrUrl($iud));
    },
    'códigos oficiais: isenções e unidades (com textos e abreviaturas antigas)' => function () {
        same('3', Efatura::exemptionCode('3'));
        same('3', Efatura::exemptionCode('Isento nos termos do artigo 9.º do RIVA'), 'texto antigo');
        same('4', Efatura::exemptionCode('Isento Art.º 13.º CIVA'), 'menção oficial');
        same(null, Efatura::exemptionCode('qualquer coisa'));
        same('Isento Art.º 9.º CIVA', Efatura::exemptionLabel('3'));
        same('EA', Efatura::unitCode('UN'));
        same('KGM', Efatura::unitCode('kg'));
        same('HUR', Efatura::unitCode('HUR'));
        same('', Efatura::unitCode('XPTO'));
        same('kg', Efatura::unitShort('KGM'));
        same(21, count(Sales::exemptions()));
    },
    'configuração: validações, chave escondida e o que falta para emitir' => function () {
        [$db] = salesSetup();
        throws(fn() => Efatura::saveSettings($db, ['enabled' => '1']), 'LED');
        throws(fn() => Efatura::saveSettings($db, ['led' => 'abc']), 'LED');
        throws(fn() => Efatura::saveSettings($db, ['led' => '1', 'software_code' => 'IDEIA-1']), 'código do software');
        throws(fn() => Efatura::saveSettings($db, ['led' => '1', 'repo' => '9']), 'Repositório');
        $s = Efatura::saveSettings($db, ['led' => '1', 'software_code' => 'ideia1', 'mw_key' => 'segredo']);
        same('IDEIA1', $s['software_code']);
        check($s['mw_key_set'] && !isset($s['mw_key']), 'a chave não é devolvida');
        same('https://localhost:3443', $s['mw_url']);
        check(!Efatura::enabled($db));
        $r = implode(' ', Efatura::readiness($db));
        check(str_contains($r, 'Email') && str_contains($r, 'Telefone') && str_contains($r, 'Morada'), $r);
    },
    'XML de todos os tipos cumpre o XSD oficial (FT, FR, TV, RC, NC, ND, GE, GT)' => function () {
        [$db, $cli, $prod, $serv] = efaturaSetup();
        $ft = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-01', 'third_party' => $cli, 'issue' => true, 'reference' => 'ENC-15', 'notes' => 'Entrega no estaleiro da obra',
            'lines' => [['item_id' => $prod['id'], 'qty' => '3', 'discount' => '10'], ['item_id' => $serv['id'], 'qty' => '1,5']]]);
        check(Efatura::validIud((string) $ft['iud']), 'IUD atribuído na emissão');
        same('pendente', $ft['efatura_status']);
        $xml = assertValid($db, (int) $ft['id'], 'FT');
        check(str_contains($xml, '<TaxExemptionReasonCode>3</TaxExemptionReasonCode>'), 'isenção com código oficial');
        check(str_contains($xml, '<Discount ValueType="P">10</Discount>'), 'desconto');
        check(str_contains($xml, '<PriceExtension>2550</PriceExtension>') && str_contains($xml, '<NetTotal>2295</NetTotal>'), 'valor ilíquido e líquido da linha');
        check(str_contains($xml, '<PayableAmount>' . rtrim(rtrim(number_format($ft['total_cents'] / 100, 2, '.', ''), '0'), '.') . '</PayableAmount>'), 'total');
        check(!str_contains($xml, '<!--') && !str_contains($xml, 'ds:'), 'sem comentários nem prefixos');
        $fr = Sales::save($db, ['type' => 'FR', 'date' => '2025-04-02', 'third_party' => $cli, 'payment_method' => 'TB', 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        check(str_contains(assertValid($db, (int) $fr['id'], 'FR'), '<PaymentMeansCode>31</PaymentMeansCode>'), 'transferência = 31');
        $tv = Sales::save($db, ['type' => 'TV', 'date' => '2025-04-02', 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        check(!str_contains(assertValid($db, (int) $tv['id'], 'TV'), 'ReceiverParty'), 'talão sem adquirente');
        $rc = Sales::pay($db, (int) $ft['id'], ['date' => '2025-04-03', 'amount' => '1 000,00', 'payment_method' => 'NU']);
        check(str_contains(assertValid($db, (int) $rc['id'], 'RC'), '<FiscalDocument IsOldDocument="false">' . $ft['iud'] . '</FiscalDocument>'), 'recibo refere o IUD da fatura');
        $nc = Sales::derive($db, (int) $ft['id'], 'NC', 'credit');
        $nc = Sales::save($db, ['id' => $nc['id'], 'date' => '2025-04-04', 'issue_reason' => '2', 'lines' => [['item_id' => $prod['id'], 'qty' => '1']], 'issue' => true]);
        check(str_contains(assertValid($db, (int) $nc['id'], 'NC'), '<IssueReasonCode>2</IssueReasonCode>'));
        throws(fn() => Sales::save($db, ['type' => 'ND', 'date' => '2025-04-04', 'third_party' => $cli, 'issue_reason' => '4', 'issue' => true, 'lines' => [['description' => 'Juros', 'price' => '100']]]), 'fatura a que se refere');
        throws(fn() => Sales::save($db, ['type' => 'NC', 'date' => '2025-04-04', 'origin_id' => $ft['id'], 'issue_reason' => 'DD', 'lines' => [['item_id' => $prod['id']]]]), 'Motivo de emissão inválido');
        $nd = Sales::derive($db, (int) $ft['id'], 'ND', 'convert');
        $nd = Sales::save($db, ['id' => $nd['id'], 'date' => '2025-04-05', 'issue_reason' => 'DD', 'lines' => [['description' => 'Despesas de envio', 'price' => '500', 'unit' => 'LS']], 'issue' => true]);
        assertValid($db, (int) $nd['id'], 'ND');
        $ge = Sales::save($db, ['type' => 'GE', 'date' => '2025-04-05', 'third_party' => $cli, 'delivery_place' => 'Obra em Palmarejo', 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '10']]]);
        $gt = Sales::save($db, ['type' => 'GT', 'date' => '2025-04-06', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '5']]]);
        same('GE 2025/1', $ge['doc_no']);
        same('GT 2025/2', $gt['doc_no'], 'guias com numeração comum (DTE)');
        assertValid($db, (int) $ge['id'], 'GE');
        check(str_contains(assertValid($db, (int) $gt['id'], 'GT'), '<TransportDocumentTypeCode>2</TransportDocumentTypeCode>'));
        same(true, Sales::verifyChain($db)['ok'], 'cadeia de hash com contador partilhado');
    },
    'dados em falta e documentos anteriores à adesão' => function () {
        [$db, $cli, $prod] = efaturaSetup();
        $db->run("UPDATE third_parties SET address = NULL, city = NULL WHERE id = ?", [$cli]);
        $ft = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-01', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        throws(fn() => Efatura::buildXml($db, (int) $ft['id']), 'Morada do cliente');
        $db->setMeta('efatura_since', '2025-05-01');
        $old = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-02', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        same('excluido', $old['efatura_status'], 'antes da data de início não se comunica');
        throws(fn() => Sales::save($db, ['type' => 'FT', 'date' => '2025-04-03', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'unit' => 'caixotes']]]), 'lista oficial de unidades');
    },
    'envio pelo Middleware: autorizado, rejeitado e sem ligação (contingência)' => function () {
        [$db, $cli, $prod] = efaturaSetup();
        $a = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-01', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        $b = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-02', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        $c = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-03', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        $seen = [];
        $transport = function (string $url, string $zip, array $headers, bool $verify) use (&$seen, $a, $b) {
            $seen[] = [$url, $headers, $verify];
            $tmp = tempnam(sys_get_temp_dir(), 'z');
            file_put_contents($tmp, $zip);
            $z = new ZipArchive();
            $z->open($tmp);
            $name = $z->getNameIndex(0);
            $xml = $z->getFromIndex(0);
            $z->close();
            unlink($tmp);
            check($name === substr($name, 0, 45) . '.xml' && Efatura::validIud(substr($name, 0, 45)), 'ficheiro com o nome do IUD');
            check(Efatura::validate($xml) === [], 'XML válido no ZIP');
            if (str_contains($xml, '<DocumentNumber>1</DocumentNumber>')) {
                return [200, '{"succeeded":true,"messages":[]}'];
            }
            if (str_contains($xml, '<DocumentNumber>2</DocumentNumber>')) {
                return [400, '{"succeeded":false,"messages":[{"code":"E123","description":"NIF do adquirente inválido"}]}'];
            }
            throw new RuntimeException('Connection refused', Efatura::OFFLINE);
        };
        $r = Efatura::send($db, [$a['id'], $b['id'], $c['id']], $transport);
        same(['autorizado', 'rejeitado', 'pendente'], array_column($r, 'status'));
        same('https://localhost:3443/v1/dfe', $seen[0][0]);
        check(in_array('cv-ef-mw-core-transmitter-key: ' . str_repeat('a', 64), $seen[0][1], true) && in_array('cv-ef-repository-code: 3', $seen[0][1], true), 'cabeçalhos do Middleware');
        same('E123 NIF do adquirente inválido', $r[1]['message']);
        same(2, (int) $db->value('SELECT efatura_mode FROM sales_docs WHERE id = ?', [$c['id']]), 'sem ligação passa a contingência');
        $again = Efatura::send($db, [$a['id']], $transport);
        same('Já tratado.', $again[0]['message']);
        $docs = Efatura::documents($db);
        same(1, $docs['counts']['autorizado']);
        same(1, Efatura::exclude($db, [$c['id'], $a['id']], true), 'autorizados não se excluem');
        $html = SalesPrint::html($db, (int) $c['id']);
        check(str_contains($html, '<svg') && str_contains($html, 'Consulta DFE em efatura.cv'), 'QR e IUD impressos');
        $html = SalesPrint::html($db, (int) $b['id']);
        check(str_contains($html, 'Pendente de Autorização') && str_contains($html, 'não confere direito a dedução'), 'aviso de contingência');
        check(!str_contains(SalesPrint::html($db, (int) $a['id']), 'Pendente de Autorização'), 'autorizado sem aviso');
    },
    'migração v7 → v8 acrescenta as colunas da e-Fatura' => function () {
        [$db] = salesSetup();
        $db->run('DROP INDEX IF EXISTS ux_sales_iud');
        foreach (['iud', 'efatura_message', 'efatura_at', 'efatura_mode', 'issue_reason'] as $c) {
            $db->run("ALTER TABLE sales_docs DROP COLUMN $c");
        }
        $db->setMeta('schema_version', '7');
        $db2 = new \Ideia\Db($db->path);
        same((string) \Ideia\Db::SCHEMA_VERSION, $db2->meta('schema_version'));
        $cols = array_column($db2->all('PRAGMA table_info(sales_docs)'), 'name');
        check(!array_diff(['iud', 'efatura_message', 'efatura_at', 'efatura_mode', 'issue_reason'], $cols), 'colunas criadas');
    },
];
