<?php
use Ideia\Bank;
use Ideia\Ledger;

/** Extrato típico de um banco local: linhas de cabeçalho antes da tabela, Windows-1252, débito/crédito separados. */
function bankCsv(): string
{
    $csv = "Banco Exemplo de Cabo Verde\nConta n.º 1234567890;Moeda: ECV\n\n"
        . "Data Mov.;Data Valor;Descritivo;Referência;Débito;Crédito;Saldo\n"
        . "03/01/2025;03/01/2025;Depósito realização capital;DEP 1;;1.000.000,00;1.000.000,00\n"
        . "02/03/2025;02/03/2025;Transferência recebida Cliente Praia;TRF 55;;115.000,00;1.115.000,00\n"
        . "31/03/2025;31/03/2025;Comissão manutenção conta;;350,00;;1.114.650,00\n"
        . "Saldo final;;;;;;1.114.650,00\n";
    return mb_convert_encoding($csv, 'Windows-1252', 'UTF-8');
}

return [
    'lê extratos com cabeçalho do banco, Windows-1252 e débito/crédito' => function () {
        $p = Bank::parse(bankCsv());
        same([], $p['errors']);
        same(3, count($p['lines']));
        same('2025-01-03', $p['lines'][0]['date']);
        same(100000000, $p['lines'][0]['amount_cents']);
        same(-35000, $p['lines'][2]['amount_cents']);
        same('Comissão manutenção conta', $p['lines'][2]['description']);
        // coluna única de montante com sinal
        $p2 = Bank::parse("data;descricao;montante\n2025-05-01;Pagamento;-1.250,50\n2025-05-02;Depósito;300\n");
        same([-125050, 30000], array_column($p2['lines'], 'amount_cents'));
        throws(fn() => Bank::parse("nome;valor\nx;1\n"), 'cabeçalho');
    },
    'importa, ignora duplicados, concilia automaticamente e cria lançamento da comissão' => function () {
        $db = demoCompany();
        $pre = Bank::import($db, '121', bankCsv(), false);
        same(3, $pre['new_lines']);
        same(0, (int) $db->value('SELECT COUNT(*) FROM bank_lines'), 'pré-visualização não grava');
        same(0, $pre['opening_cents'], 'saldo inicial deduzido da coluna saldo');
        $r = Bank::import($db, '121', bankCsv(), true, 'extrato.csv');
        check($r['committed']);
        same(2, $r['auto_matched'], 'capital e recebimento conciliados automaticamente');
        throws(fn() => Bank::import($db, '121', bankCsv(), true), 'já tinham sido importados');

        $pend = Bank::pending($db, '121');
        same(1, count($pend['bank']));
        same(0, count($pend['ledger']));
        $rep = $pend['summary'];
        same(111465000, $rep['bank_balance']);
        same(111500000, $rep['book_balance']);
        same(-35000, $rep['bank_out']);
        same(0, $rep['difference'], 'diferença explicada pelos movimentos por conciliar');

        $e = Bank::createEntry($db, (int) $pend['bank'][0]['id'], '698', 'Comissão bancária março');
        same('BCO', $e['journal']);
        $rep = Bank::report($db, '121', '2025-03-31');
        same(111465000, $rep['book_balance']);
        same(0, count($rep['pending_bank']));
        same(0, $rep['difference']);
        check(Ledger::verifyChain($db)['ok']);
    },
    'conciliação manual com totais iguais, desfazer e apagar extrato' => function () {
        $db = demoCompany();
        Bank::import($db, '121', "data;descricao;montante\n2025-01-20;Depósito capital;1000000\n2025-03-01;Recebimento;115000\n", true);
        // datas a mais de 7 dias: o capital não concilia sozinho
        $pend = Bank::pending($db, '121');
        same(1, count($pend['bank']));
        same(1, count($pend['ledger']));
        throws(fn() => Bank::match($db, '121', [(int) $pend['bank'][0]['id']], []), 'Selecione');
        $mid = Bank::match($db, '121', [(int) $pend['bank'][0]['id']], [(int) $pend['ledger'][0]['id']]);
        same(0, count(Bank::pending($db, '121')['bank']));
        same(2, count(Bank::matches($db, '121')));
        Bank::unmatch($db, $mid);
        same(1, count(Bank::pending($db, '121')['bank']));
        // totais diferentes são recusados
        Bank::import($db, '121', "data;descricao;montante\n2025-04-01;Outro;10\n", true);
        $p = Bank::pending($db, '121');
        $other = array_values(array_filter($p['bank'], fn($b) => (int) $b['amount_cents'] === 1000))[0];
        throws(fn() => Bank::match($db, '121', [(int) $other['id']], [(int) $p['ledger'][0]['id']]), 'não coincidem');
        $sid = (int) $db->value('SELECT id FROM bank_statements ORDER BY id LIMIT 1');
        Bank::deleteStatement($db, $sid);
        same(1, (int) $db->value('SELECT COUNT(*) FROM bank_lines'));
        same(0, (int) $db->value('SELECT COUNT(*) FROM bank_match_ledger'));
        throws(fn() => Bank::import($db, '211', "data;montante\n2025-01-01;1\n", false), 'conta de depósitos');
    },
];
