<?php
use Ideia\Db;
use Ideia\Ledger;
use Ideia\Sales;
use Ideia\Saft\Exporter;

return [
    'associar documentos: orçamento ↔ fatura feita à parte, regras, remover e efeito no orçamento em aberto e no SAF-T' => function () {
        [$db, $cli, $prod] = salesSetup();
        $or = Sales::save($db, ['type' => 'OR', 'date' => '2025-03-01', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        $ft = Sales::save($db, ['type' => 'FT', 'date' => '2025-03-05', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        $other = Ledger::saveThirdParty($db, ['name' => 'Outro', 'nif' => '255555555', 'kind' => 'C'])['id'];
        $ft2 = Sales::save($db, ['type' => 'FT', 'date' => '2025-03-06', 'third_party' => $other, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);

        $d = Sales::link($db, (int) $ft['id'], (int) $or['id']);
        same([(int) $or['id']], array_map('intval', array_column($d['links'], 'id')));
        same([(int) $ft['id']], array_map('intval', array_column(Sales::get($db, (int) $or['id'])['links'], 'id')), 'visível nos dois');
        Sales::link($db, (int) $or['id'], (int) $ft['id']); // repetir não duplica
        same(1, (int) $db->value('SELECT COUNT(*) FROM sales_doc_links'));
        throws(fn() => Sales::link($db, (int) $ft['id'], (int) $ft['id']), 'outro documento');
        throws(fn() => Sales::link($db, (int) $ft2['id'], (int) $or['id']), 'mesmo cliente');
        $nc = Sales::derive($db, (int) $ft['id'], 'NC', 'credit');
        throws(fn() => Sales::link($db, (int) $nc['id'], (int) $ft['id']), 'já estão ligados');

        $f = Exporter::export($db, 2025, 'F');
        check(str_contains($f, '<WorkStatus>F</WorkStatus>'), 'orçamento associado a fatura conta como faturado');
        $sum = Sales::summary($db, 2025);
        Sales::unlink($db, (int) $or['id'], (int) $ft['id']);
        same([], Sales::get($db, (int) $ft['id'])['links']);
        check(str_contains(Exporter::export($db, 2025, 'F'), '<WorkStatus>N</WorkStatus>'), 'sem a associação volta a pendente');
        check((bool) $db->value("SELECT 1 FROM audit_log WHERE action = 'venda.associada'"));
    },
    'duplicar: mesmo tipo sem associação, ou outro tipo associado ao original; apagar o rascunho remove a associação' => function () {
        [$db, $cli, $prod] = salesSetup();
        $or = Sales::save($db, ['type' => 'OR', 'date' => '2025-03-01', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '3']]]);
        $c1 = Sales::derive($db, (int) $or['id'], 'OR', 'copy');
        same(['OR', 'rascunho', null, []], [$c1['type'], $c1['status'], $c1['origin_id'], $c1['links']]);
        $c2 = Sales::derive($db, (int) $or['id'], 'PF', 'copy', true);
        same('PF', $c2['type']);
        same(3000, (int) $c2['lines'][0]['qty_milli']);
        same([(int) $or['id']], array_map('intval', array_column($c2['links'], 'id')));
        throws(fn() => Sales::derive($db, (int) $or['id'], 'NC', 'copy'), 'notas de crédito');
        throws(fn() => Sales::derive($db, (int) $or['id'], 'RC', 'copy'), 'recibos');
        Sales::deleteDraft($db, (int) $c2['id']);
        same(0, (int) $db->value('SELECT COUNT(*) FROM sales_doc_links'));
    },
    'migração v9 → v10 cria a tabela de associações' => function () {
        $db = demoCompany();
        $db->run('DROP TABLE sales_doc_links');
        $db->setMeta('schema_version', '9');
        $db2 = new Db($db->path);
        same((string) Db::SCHEMA_VERSION, $db2->meta('schema_version'));
        check((bool) $db2->value("SELECT 1 FROM sqlite_master WHERE name = 'sales_doc_links'"));
    },
];
