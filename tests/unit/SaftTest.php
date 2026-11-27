<?php
use Ideia\Ledger;
use Ideia\Purchases;
use Ideia\Sales;
use Ideia\Saft\Exporter;

return [
    'SAF-T CV: contabilidade (C) e completo (F) cumprem o XSD oficial 1.01_01' => function () {
        [$db, $cli, $prod, $serv] = salesSetup();
        $sup = Ledger::saveThirdParty($db, ['name' => 'Fornecedor Lda', 'nif' => '222222222', 'kind' => 'F', 'account' => '221'])['id'];
        $or = Sales::save($db, ['type' => 'OR', 'date' => '2025-03-01', 'third_party' => $cli, 'issue' => true,
            'lines' => [['item_id' => $prod['id'], 'qty' => '2'], ['item_id' => $serv['id']], ['description' => 'Montagem', 'price' => '1 000,00']]]);
        Sales::save($db, ['type' => 'PF', 'date' => '2025-03-02', 'third_party' => $cli, 'issue' => true, 'lines' => [['item_id' => $prod['id']]]]);
        $ft = Sales::derive($db, (int) $or['id'], 'FT');
        $ft = Sales::save($db, ['id' => $ft['id'], 'date' => '2025-03-05', 'lines' => $ft['lines'], 'issue' => true]);
        Sales::pay($db, (int) $ft['id'], ['date' => '2025-03-20', 'amount' => '1 000,00', 'payment_method' => 'TB']);
        $fc = Purchases::save($db, ['type' => 'FC', 'date' => '2025-03-03', 'due_date' => '2025-04-03', 'third_party' => $sup, 'supplier_doc' => 'FT 9/2025', 'register' => true,
            'lines' => [['description' => 'Eletricidade', 'price' => '5.000,00', 'account' => '624']]]);
        Purchases::pay($db, ['invoices' => [$fc['id']], 'date' => '2025-03-25', 'payment_method' => 'TB']);

        $c = Exporter::export($db, 2025, 'C');
        same([], Exporter::validate($c), 'ficheiro C');
        check(str_contains($c, '<FileContentType>C</FileContentType>') && !str_contains($c, '<SourceDocuments>'));
        check(!preg_match('#<AccountID>\d</AccountID>#', $c), 'classes de 1 dígito não são exportadas');

        $f = Exporter::export($db, 2025, 'F');
        same([], Exporter::validate($f), 'ficheiro F');
        check(str_contains($f, '<DocumentNumber>OR/2025/1</DocumentNumber>') && str_contains($f, '<WorkStatus>F</WorkStatus>'), 'orçamento faturado');
        check(str_contains($f, '<DocumentNumber>PF/2025/1</DocumentNumber>'), 'pró-forma');
        check(str_contains($f, '<PaymentRefNo>RC/2025/1</PaymentRefNo>') && str_contains($f, '<PaymentType>PF</PaymentType>'), 'recibo e pagamento a fornecedor');
        check(str_contains($f, '<ProductCode>DIVERSOS</ProductCode>') && str_contains($f, '<TaxExemptionCode>3</TaxExemptionCode>'), 'linha sem artigo e isenção');
        check(!str_contains($f, '<DocumentNumber>FT'), 'faturas vão para a e-Fatura, não para o SAF-T');
    },
];
