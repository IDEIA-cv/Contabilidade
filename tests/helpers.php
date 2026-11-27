<?php
use Ideia\Companies;
use Ideia\Ledger;
use Ideia\Db;

/** Cria uma empresa de teste com alguns lançamentos típicos. */
function demoCompany(string $name = 'Teste Lda'): Db
{
    $c = Companies::create(['name' => $name . ' ' . uniqid(), 'nif' => '200000001', 'year' => 2025]);
    $db = Companies::open($c['id']);
    $cli = Ledger::saveThirdParty($db, ['name' => 'Cliente Praia', 'nif' => '123456789', 'kind' => 'C']);
    // capital inicial
    Ledger::saveEntry($db, ['date' => '2025-01-02', 'journal' => 'BCO', 'description' => 'Realização de capital', 'validate' => true, 'lines' => [
        ['account' => '121', 'debit' => '1.000.000,00'],
        ['account' => '51', 'credit' => '1.000.000,00'],
    ]]);
    // venda com IVA 15%
    Ledger::saveEntry($db, ['date' => '2025-02-10', 'journal' => 'VEN', 'description' => 'Fatura FTE 1', 'document' => 'FTE 2025/1', 'validate' => true, 'lines' => [
        ['account' => '211', 'debit' => '115000', 'third_party' => $cli['id']],
        ['account' => '721', 'credit' => '100000'],
        ['account' => '2433', 'credit' => '15000', 'vat_code' => 'NOR', 'vat_base' => '100000'],
    ]]);
    // compra de serviços com IVA
    Ledger::saveEntry($db, ['date' => '2025-02-15', 'journal' => 'COM', 'description' => 'Fatura fornecedor energia', 'validate' => true, 'lines' => [
        ['account' => '624', 'debit' => '20000'],
        ['account' => '2432', 'debit' => '3000', 'vat_code' => 'DED', 'vat_base' => '20000'],
        ['account' => '221', 'credit' => '23000'],
    ]]);
    // recebimento
    Ledger::saveEntry($db, ['date' => '2025-03-01', 'journal' => 'BCO', 'description' => 'Recebimento cliente', 'validate' => true, 'lines' => [
        ['account' => '121', 'debit' => '115000'],
        ['account' => '211', 'credit' => '115000', 'third_party' => $cli['id']],
    ]]);
    return $db;
}

