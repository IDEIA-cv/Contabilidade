<?php
// Regras de validação acrescentadas na revisão de qualidade por módulo (27/11/2026).
use Ideia\Ledger;
use Ideia\Sales;
use Ideia\Purchases;
use Ideia\Vat;
use Ideia\Assets;
use Ideia\Auth;

return [
    'IVA: o mesmo período não pode ser apurado duas vezes; estornar permite refazer' => function () {
        $db = demoCompany();
        throws(fn() => Vat::postSettlement($db, 2025, 3, 1), 'Período inválido');
        $e = Vat::postSettlement($db, 2025, 1, 3);
        same([[1, 3]], array_map(fn($p) => [$p[0], $p[1]], Vat::settlement($db, 2025, 2, 2)['posted']), 'período apurado sobrepõe fevereiro');
        throws(fn() => Vat::postSettlement($db, 2025, 1, 3), 'já foi apurado');
        throws(fn() => Vat::postSettlement($db, 2025, 2, 4), 'já foi apurado');
        same([], Vat::settlement($db, 2025, 4, 6)['posted']);
        Ledger::reverseEntry($db, (int) $e['id']);
        same([], Vat::settlement($db, 2025, 1, 3)['posted'], 'estornado deixa de contar');
        $again = Vat::postSettlement($db, 2025, 1, 3);
        check((int) $again['id'] > (int) $e['id']);
    },
    'plano de contas: código só com algarismos e conta nova não substitui uma existente' => function () {
        $db = demoCompany();
        $name62 = $db->value("SELECT name FROM accounts WHERE code = '62'");
        throws(fn() => Ledger::saveAccount($db, ['code' => '62A', 'name' => 'X', 'new' => 1]), 'só pode ter algarismos');
        throws(fn() => Ledger::saveAccount($db, ['code' => '62', 'name' => 'X', 'new' => 1]), 'já existe');
        same($name62, $db->value("SELECT name FROM accounts WHERE code = '62'"), 'nome da conta 62 intacto');
        same('6889', Ledger::saveAccount($db, ['code' => '68.89', 'name' => 'Outros gastos diversos', 'new' => 1])['code'], 'pontos são aceites como separadores');
        same('Outro nome', Ledger::saveAccount($db, ['code' => '6889', 'name' => 'Outro nome'])['name'], 'alterar o nome continua possível');
    },
    'datas: recibo, nota de crédito e pagamento a fornecedor não podem ser anteriores à fatura' => function () {
        [$db, $cli, $prod] = salesSetup();
        $ft = Sales::save($db, ['type' => 'FT', 'date' => '2025-06-10', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id'], 'qty' => '1']]]);
        throws(fn() => Sales::pay($db, (int) $ft['id'], ['date' => '2025-06-09', 'amount' => '100', 'payment_method' => 'NU']), 'data anterior');
        Sales::pay($db, (int) $ft['id'], ['date' => '2025-06-10', 'amount' => '100', 'payment_method' => 'NU']);
        $nc = Sales::derive($db, (int) $ft['id'], 'NC', 'credit');
        throws(fn() => Sales::save($db, ['id' => $nc['id'], 'date' => '2025-06-01', 'issue_reason' => '2', 'issue' => true, 'lines' => [['description' => 'Devolução', 'qty' => '1', 'price' => '100']]]), 'data anterior');
        $sup = Ledger::saveThirdParty($db, ['name' => 'Fornecedor Datas', 'nif' => '200000077', 'kind' => 'F', 'account' => '221']);
        $fc = Purchases::save($db, ['type' => 'FC', 'date' => '2025-06-10', 'due_date' => '2025-07-10', 'third_party' => $sup['id'], 'supplier_doc' => 'D1', 'register' => true,
            'lines' => [['description' => 'Serviço', 'price' => '1000', 'account' => '6268']]]);
        throws(fn() => Purchases::pay($db, ['invoices' => [$fc['id']], 'date' => '2025-06-01', 'payment_method' => 'TB']), 'data anterior');
        $nf = Purchases::creditFrom($db, (int) $fc['id']);
        throws(fn() => Purchases::save($db, ['id' => $nf['id'], 'date' => '2025-06-05', 'supplier_doc' => 'NC1', 'register' => true, 'lines' => [['description' => 'x', 'price' => '10', 'account' => '6268']]]), 'data anterior');
        Purchases::pay($db, ['invoices' => [$fc['id']], 'date' => '2025-06-12', 'payment_method' => 'TB']);
        same(0, Purchases::openAmount($db, (int) $fc['id']), 'pagamento na data certa liquida a fatura');
    },
    'imobilizado: proteções (apagar, alterar valores, lançar duas vezes, ordem dos exercícios, abate repetido)' => function () {
        $db = demoCompany();
        Ledger::createFiscalYear($db, 2026);
        $a = Assets::save($db, ['category' => 'informatica', 'description' => 'Servidor', 'acquisition_date' => '2025-01-10', 'cost' => '36.000,00']);
        throws(fn() => Assets::save($db, ['category' => 'informatica', 'description' => 'X', 'acquisition_date' => '2025-05-10', 'start_date' => '2025-01-01', 'cost' => '1000']), 'anterior à aquisição');
        throws(fn() => Assets::save($db, ['category' => 'informatica', 'description' => 'X', 'acquisition_date' => '2025-05-10', 'cost' => '1000', 'residual' => '1000']), 'residual');
        throws(fn() => Assets::post($db, 2026), 'Lance primeiro');
        Assets::post($db, 2025);
        throws(fn() => Assets::post($db, 2025), 'Não há depreciações por lançar');
        throws(fn() => Assets::delete($db, (int) $a['id']), 'não pode ser apagado');
        throws(fn() => Assets::save($db, ['id' => $a['id'], 'category' => 'informatica', 'description' => 'Servidor', 'acquisition_date' => '2025-01-10', 'cost' => '50.000,00']), 'só pode mudar');
        same('Servidor principal', Assets::save($db, ['id' => $a['id'], 'category' => 'informatica', 'description' => 'Servidor principal', 'acquisition_date' => '2025-01-10', 'cost' => '36.000,00', 'location' => 'Sala 2'])['description']);
        Assets::post($db, 2026);
        throws(fn() => Assets::unpost($db, 2025), 'seguintes');
        Assets::unpost($db, 2026);
        Assets::dispose($db, (int) $a['id'], ['date' => '2026-06-30', 'reason' => 'Abate', 'value' => '0']);
        throws(fn() => Assets::dispose($db, (int) $a['id'], ['date' => '2026-07-30', 'reason' => 'Abate', 'value' => '0']), 'já foi abatido');
        same('abatido', Assets::get($db, (int) $a['id'])['status']);
        check(Ledger::verifyChain($db)['ok'] ?? true, 'cadeia íntegra');
    },
    'utilizadores: há sempre um contabilista ativo e os dados de cada utilizador são validados' => function () {
        Auth::actAs(null);
        @unlink(\Ideia\Companies::dataDir() . '/acesso.json');
        Auth::setup('chefe', 'Chefe', 'segredo1');
        throws(fn() => Auth::saveUser(['username' => 'chefe', 'name' => 'Chefe', 'role' => 'colaborador', 'existing' => 1, 'active' => 1]), 'administrador ativo');
        throws(fn() => Auth::saveUser(['username' => 'chefe', 'name' => 'Chefe', 'role' => 'admin', 'existing' => 1, 'active' => 0]), 'administrador ativo');
        throws(fn() => Auth::saveUser(['username' => 'a b', 'name' => 'A', 'role' => 'leitura', 'password' => 'segredo1']), 'inválido');
        throws(fn() => Auth::saveUser(['username' => 'ana', 'name' => 'A', 'role' => 'leitura', 'password' => '123']), '6 caracteres');
        throws(fn() => Auth::saveUser(['username' => 'ana', 'name' => 'A', 'role' => 'root', 'password' => 'segredo1']), 'Perfil');
        Auth::saveUser(['username' => 'ana', 'name' => 'Ana', 'role' => 'leitura', 'password' => 'segredo1']);
        throws(fn() => Auth::saveUser(['username' => 'ana', 'name' => 'A', 'role' => 'leitura', 'password' => 'segredo1']), 'Já existe');
        throws(fn() => Auth::deleteUser('chefe'), 'próprio');
        Auth::disable('segredo1');
        same(false, Auth::enabled());
        Auth::actAs(null);
    },
];
