<?php
use Ideia\Attachments;
use Ideia\Companies;
use Ideia\Db;
use Ideia\Ledger;

return [
    'anexa, lista, descarrega e protege anexos de lançamentos validados' => function () {
        $db = demoCompany();
        $entryId = (int) $db->value("SELECT id FROM entries WHERE status = 'validado' ORDER BY id LIMIT 1");
        $pdf = "%PDF-1.4\n" . str_repeat("\x00\xff binário ", 50);
        $a = Attachments::add($db, $entryId, 'Fatura nº 12.pdf', $pdf);
        same('application/pdf', $a['mime']);
        same(strlen($pdf), (int) $a['size']);
        [$content, $name] = Attachments::get($db, (int) $a['id']);
        same($pdf, $content, 'conteúdo binário intacto');
        same('Fatura nº 12.pdf', $name);
        same(1, count(Attachments::list($db, $entryId)));
        throws(fn() => Attachments::add($db, $entryId, 'copia.pdf', $pdf), 'já está anexado');
        throws(fn() => Attachments::add($db, $entryId, 'script.exe', 'MZ'), 'não aceite');
        throws(fn() => Attachments::delete($db, (int) $a['id']), 'validado');
        $list = Ledger::listEntries($db, ['year' => 2025]);
        $row = array_values(array_filter($list['rows'], fn($r) => (int) $r['id'] === $entryId))[0];
        same(1, (int) $row['attachments']);
        // a cadeia de integridade não é afetada pelos anexos
        check(Ledger::verifyChain($db)['ok']);
    },
    'anexos de rascunhos podem ser retirados e saem com o rascunho' => function () {
        $db = demoCompany();
        $d = Ledger::saveEntry($db, ['date' => '2025-04-01', 'journal' => 'DIV', 'description' => 'Rascunho', 'lines' => [['account' => '6268', 'debit' => '10'], ['account' => '111', 'credit' => '10']]]);
        $a = Attachments::add($db, (int) $d['id'], 'recibo.jpg', 'JPEGDATA');
        Attachments::delete($db, (int) $a['id']);
        same(0, count(Attachments::list($db, (int) $d['id'])));
        Attachments::add($db, (int) $d['id'], 'recibo.png', 'PNGDATA');
        Ledger::deleteDraft($db, (int) $d['id']);
        same(0, (int) $db->value('SELECT COUNT(*) FROM entry_attachments'));
    },
    'migra um ficheiro da versão 1 para a atual' => function () {
        $db = demoCompany();
        $db->pdo->exec('DROP TABLE entry_attachments');
        $db->setMeta('schema_version', '1');
        $db2 = new Db($db->path);
        same('3', $db2->meta('schema_version'));
        same(0, (int) $db2->value('SELECT COUNT(*) FROM entry_attachments'));
        check(Ledger::verifyChain($db2)['ok']);
    },
    'cópia automática: uma por dia e só com alterações' => function () {
        $db = demoCompany();
        $id = basename($db->path, '.ideia');
        $f = Companies::autoBackup($id);
        check($f !== null && str_ends_with($f, '_auto.ideia') && is_file($f), 'primeira cópia criada');
        same(null, Companies::autoBackup($id), 'não repete no mesmo dia');
        // simula cópia antiga sem alterações posteriores
        $db->run("UPDATE audit_log SET ts = datetime('now', '-3 days')");
        same(null, Companies::autoBackup($id), 'sem alterações não copia');
        $db->audit('teste.alteracao');
        check(Companies::autoBackup($id) !== null, 'com alterações volta a copiar');
    },
];
