<?php
use Ideia\Attachments;
use Ideia\DocExport;
use Ideia\Ledger;
use Ideia\Purchases;
use Ideia\Sales;

function unzip(string $zip): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'uz');
    file_put_contents($tmp, $zip);
    $z = new ZipArchive();
    $z->open($tmp);
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $out[$z->getNameIndex($i)] = $z->getFromIndex($i);
    }
    $z->close();
    unlink($tmp);
    return $out;
}

return [
    'exportar documentos de venda para ZIP: por filtro e por seleção, com HTML, XML da e-Fatura e listas CSV' => function () {
        [$db, $cli, $prod] = efaturaSetup();
        $ft = Sales::save($db, ['type' => 'FT', 'date' => '2025-04-01', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '2']]]);
        $or = Sales::save($db, ['type' => 'OR', 'date' => '2025-04-02', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        Sales::save($db, ['type' => 'FT', 'date' => '2025-05-01', 'third_party' => $cli, 'lines' => [['item_id' => $prod['id']]]]); // rascunho

        [$zip, $name, $n] = DocExport::sales($db, ['type' => 'faturas', 'from' => '2025-04-01', 'to' => '2025-04-30']);
        same(1, $n, 'só a fatura de abril');
        check(str_starts_with($name, 'documentos_vendas_200000001_') && str_ends_with($name, '.zip'));
        $f = unzip($zip);
        $no = str_replace('/', '-', $ft['doc_no']);
        check(isset($f["Faturas/$no.html"], $f["Faturas/$no.xml"], $f['documentos.csv'], $f['linhas.csv'], $f['LEIA-ME.txt']), implode(', ', array_keys($f)));
        check(!str_contains($f["Faturas/$no.html"], 'window.print()'), 'sem a barra de botões');
        check(str_starts_with($f['documentos.csv'], "\xEF\xBB\xBF") && str_contains($f['documentos.csv'], $ft['doc_no']), 'CSV com BOM');
        same(2, substr_count(trim($f['documentos.csv']), "\r\n") + 1, 'cabeçalho + 1 documento');

        [$zip, , $n] = DocExport::sales($db, ['ids' => $or['id'] . ',' . $ft['id'] . ',999999']);
        same(2, $n, 'ids inexistentes ignorados');
        check(isset(unzip($zip)['Orçamentos/' . str_replace('/', '-', $or['doc_no']) . '.html']));

        [, , $n] = DocExport::sales($db, ['status' => 'rascunho']);
        same(1, $n, 'rascunhos também');
        throws(fn() => DocExport::sales($db, ['from' => '2030-01-01']), 'Não há documentos');
        throws(fn() => DocExport::sales($db, ['ids' => implode(',', range(1, 1001))]), 'no máximo');
    },
    'exportar compras para ZIP com os anexos dos lançamentos' => function () {
        [$db] = salesSetup();
        $sup = Ledger::saveThirdParty($db, ['name' => 'Fornecedor Lda', 'nif' => '222222222', 'kind' => 'F', 'account' => '221'])['id'];
        $fc = Purchases::save($db, ['type' => 'FC', 'date' => '2025-03-03', 'third_party' => $sup, 'supplier_doc' => 'FT 9/2025', 'register' => true,
            'lines' => [['description' => 'Eletricidade', 'price' => '5.000,00', 'account' => '624']]]);
        Attachments::add($db, (int) $fc['entry_id'], 'fatura.pdf', '%PDF-1.4 teste');
        [$zip, $name, $n] = DocExport::purchases($db, ['type' => 'FC']);
        same(1, $n);
        check(str_contains($name, 'compras'));
        $f = unzip($zip);
        $att = 'Faturas de fornecedores/' . str_replace('/', '-', $fc['doc_no']) . ' - fatura.pdf';
        same('%PDF-1.4 teste', $f[$att] ?? null, implode(', ', array_keys($f)));
        check(str_contains($f['documentos.csv'], 'FT 9/2025') && str_contains($f['linhas.csv'], 'Eletricidade') && str_contains($f['linhas.csv'], '624'));
    },
];
