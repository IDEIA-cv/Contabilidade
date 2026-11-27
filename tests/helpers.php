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

/** Empresa de teste com um cliente, um produto (IVA 15%) e um serviço isento. */
function salesSetup(): array
{
    $db = demoCompany();
    $cli = (int) $db->value("SELECT id FROM third_parties WHERE name = 'Cliente Praia'");
    $prod = \Ideia\Sales::saveItem($db, ['name' => 'Cimento 50 kg', 'kind' => 'P', 'price' => '850,00', 'vat_code' => 'NOR']);
    $serv = \Ideia\Sales::saveItem($db, ['name' => 'Transporte', 'kind' => 'S', 'price' => '2 000,00', 'vat_code' => 'ISE', 'exemption' => '3']);
    return [$db, $cli, $prod, $serv];
}

/** Empresa com dados de emitente completos e e-Fatura ativa (repositório de teste). */
function efaturaSetup(): array
{
    [$db, $cli, $prod, $serv] = salesSetup();
    $db->setMeta('company_address', 'Avenida Cidade de Lisboa');
    $db->setMeta('company_city', 'Praia');
    $db->setMeta('company_email', 'geral@teste.cv');
    $db->setMeta('company_phone', '+238 260 00 00');
    $db->run("UPDATE third_parties SET address = 'Achada Santo António', city = 'Praia', email = 'compras@cliente.cv' WHERE id = ?", [$cli]);
    \Ideia\Efatura::saveSettings($db, ['enabled' => '1', 'since' => '2025-01-01', 'repo' => '3', 'led' => '7', 'software_code' => 'IDEIA01', 'mw_url' => 'https://localhost:3443', 'mw_key' => str_repeat('a', 64)]);
    return [$db, $cli, $prod, $serv];
}

function assertValid(\Ideia\Db $db, int $id, string $label): string
{
    $xml = \Ideia\Efatura::buildXml($db, $id);
    $errors = \Ideia\Efatura::validate($xml);
    check(!$errors, "$label não cumpre o XSD: " . implode(' | ', $errors));
    return $xml;
}
