<?php
declare(strict_types=1);

namespace Ideia\Saft;

use Ideia\Db;
use Ideia\Efatura;
use Ideia\Money;
use Ideia\UserError;
use XMLWriter;

/**
 * Exportação SAF-T (CV) segundo o XSD oficial 1.01_01 da DNRE (resources/efatura/xsd/saftcv1.01_01.xsd,
 * Portaria n.º 47/2021).
 *
 * Tipos de ficheiro: "C" contabilidade (plano de contas, terceiros, IVA, lançamentos validados) e "F" completo,
 * que acrescenta artigos e os documentos de origem que o SAF-T CV pede (as faturas vão para a e-Fatura):
 * documentos de conferência (orçamentos e pró-formas) e pagamentos (recibos de clientes e pagamentos a fornecedores).
 */
final class Exporter
{
    public const NS = 'urn:OECD:StandardAuditFile-Tax:CV_1.01_01';
    public const VERSION = '1.01_01';
    public const XSD = IDEIA_ROOT . '/resources/efatura/xsd/saftcv1.01_01.xsd';
    /** O XSD exige o formato 0000-000; Cabo Verde não usa códigos postais, por isso fica este valor quando não há outro. */
    private const NO_POSTAL = '0000-000';
    private const MECHANISMS = ['NU' => 'NU', 'TB' => 'TB', 'CC' => 'CC', 'CH' => 'CH', 'OU' => 'OU'];

    public static function export(Db $db, int $year, string $type = 'C'): string
    {
        $type = strtoupper($type) === 'F' ? 'F' : 'C';
        $fy = $db->one('SELECT * FROM fiscal_years WHERE year = ?', [$year]);
        if (!$fy) {
            throw new UserError('Exercício inexistente.');
        }
        $nif = (string) $db->meta('company_nif');
        if (strlen($nif) !== 9) {
            throw new UserError('Indique o NIF da empresa (9 dígitos) nos dados da empresa antes de exportar o SAF-T.');
        }
        $from = $fy['start_date'];
        $to = $fy['end_date'];
        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('AuditFile');
        $w->writeAttribute('xmlns', self::NS);

        // ---------------------------------------------------------------- Header
        $w->startElement('Header');
        self::el($w, 'AuditFileVersion', self::VERSION);
        self::el($w, 'CompanyID', $nif);
        self::el($w, 'TaxRegistrationNumber', $nif);
        self::el($w, 'FileContentType', $type);
        self::el($w, 'CompanyName', self::txt($db->meta('company_name'), 150));
        $w->startElement('CompanyAddress');
        self::el($w, 'AddressDetail', self::txt($db->meta('company_address') ?: 'Desconhecido', 255));
        self::el($w, 'City', self::txt($db->meta('company_city') ?: 'Desconhecido', 35));
        $postal = (string) $db->meta('company_postal_code');
        self::el($w, 'PostalCode', preg_match('/^\d{4}-\d{3}$/', $postal) ? $postal : self::NO_POSTAL);
        self::el($w, 'Region', self::txt($db->meta('company_city') ?: 'Desconhecido', 50));
        self::el($w, 'Country', 'CV');
        $w->endElement();
        self::el($w, 'FiscalYear', (string) $year);
        self::el($w, 'StartDate', $from);
        self::el($w, 'EndDate', $to);
        self::el($w, 'CurrencyCode', 'CVE');
        self::el($w, 'DateCreated', date('Y-m-d'));
        self::el($w, 'TaxEntity', 'Global');
        self::el($w, 'ProductCompanyTaxID', (string) ($db->meta('software_producer_nif') ?: '000000000'));
        self::el($w, 'SoftwareCertificateNumber', '0');
        self::el($w, 'ProductID', 'IDEIA Contabilidade/IDEIA');
        self::el($w, 'ProductVersion', IDEIA_VERSION);
        $phone = self::txt($db->meta('company_phone'), 18);
        if ($phone !== '') {
            self::el($w, 'Telephone', $phone);
        }
        $email = trim((string) $db->meta('company_email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 70) {
            self::el($w, 'Email', $email);
        }
        self::el($w, 'NumberOfParts', '1');
        self::el($w, 'PartNumber', '1');
        $w->endElement();

        // documentos de origem (só no ficheiro completo)
        $work = $type === 'F' ? $db->all(
            "SELECT * FROM sales_docs WHERE type IN ('OR','PF') AND status <> 'rascunho' AND date BETWEEN ? AND ? ORDER BY type, series, number", [$from, $to]) : [];
        $receipts = $type === 'F' ? $db->all(
            "SELECT * FROM sales_docs WHERE type = 'RC' AND status <> 'rascunho' AND date BETWEEN ? AND ? ORDER BY series, number", [$from, $to]) : [];
        $supplierPayments = $type === 'F' ? $db->all(
            "SELECT * FROM purchase_docs WHERE type = 'PG' AND status <> 'rascunho' AND date BETWEEN ? AND ? ORDER BY series, number", [$from, $to]) : [];

        // ---------------------------------------------------------------- MasterFiles
        $w->startElement('MasterFiles');
        $w->startElement('GeneralLedgerAccounts');
        self::el($w, 'TaxonomyReference', 'S');
        $balances = self::accountBalances($db, $year);
        $children = [];
        foreach ($db->all('SELECT code, parent FROM accounts WHERE parent IS NOT NULL') as $r) {
            $children[$r['parent']] = true;
        }
        foreach ($db->all('SELECT code, name, parent, level FROM accounts WHERE active = 1 OR code IN (SELECT DISTINCT account FROM entry_lines) ORDER BY code') as $a) {
            if (strlen($a['code']) < 2) {
                continue; // as classes (1 dígito) não são contas no SAF-T: o XSD exige pelo menos 2 carateres
            }
            $b = $balances[$a['code']] ?? ['open' => 0, 'close' => 0];
            $topLevel = $a['parent'] === null || strlen((string) $a['parent']) < 2;
            $w->startElement('Account');
            self::el($w, 'AccountID', $a['code']);
            self::el($w, 'AccountDescription', self::txt($a['name'], 255));
            self::el($w, 'OpeningDebitBalance', Money::decimal(max($b['open'], 0)));
            self::el($w, 'OpeningCreditBalance', Money::decimal(max(-$b['open'], 0)));
            self::el($w, 'ClosingDebitBalance', Money::decimal(max($b['close'], 0)));
            self::el($w, 'ClosingCreditBalance', Money::decimal(max(-$b['close'], 0)));
            // GR = conta de 1.º grau (2 dígitos), GA = agregadora, GM = de movimento
            self::el($w, 'GroupingCategory', $topLevel ? 'GR' : (isset($children[$a['code']]) ? 'GA' : 'GM'));
            if (!$topLevel) {
                self::el($w, 'GroupingCode', (string) $a['parent']);
            }
            $w->endElement();
        }
        $w->endElement();

        $ids = array_map('intval', array_column($db->all(
            "SELECT DISTINCT l.third_party AS id FROM entry_lines l JOIN entries e ON e.id = l.entry_id WHERE e.fiscal_year = ? AND e.status = 'validado' AND l.third_party IS NOT NULL",
            [$year]
        ), 'id'));
        $customerIds = [];
        $supplierIds = [];
        foreach ([...$work, ...$receipts] as $d) {
            $customerIds[(int) $d['third_party']] = true;
        }
        foreach ($supplierPayments as $d) {
            $supplierIds[(int) $d['third_party']] = true;
        }
        $all = array_unique([...$ids, ...array_keys($customerIds), ...array_keys($supplierIds)]);
        $parties = $all ? $db->all('SELECT * FROM third_parties WHERE id IN (' . implode(',', $all) . ') ORDER BY id') : [];
        foreach (['C' => 'Customer', 'F' => 'Supplier'] as $kind => $tag) {
            foreach ($parties as $t) {
                $inDocs = $kind === 'C' ? isset($customerIds[(int) $t['id']]) : isset($supplierIds[(int) $t['id']]);
                if (!$inDocs && $t['kind'] !== $kind && $t['kind'] !== 'A' && !($kind === 'C' && $t['kind'] === 'O')) {
                    continue;
                }
                $w->startElement($tag);
                self::el($w, $tag . 'ID', $kind . $t['id']);
                self::el($w, 'AccountID', $t['account'] ?: 'Desconhecido');
                self::el($w, $tag . 'TaxID', self::txt($t['nif'] ?: '999999999', 35));
                self::el($w, 'CompanyName', self::txt($t['name'], 150));
                $w->startElement('BillingAddress');
                self::el($w, 'AddressDetail', self::txt($t['address'] ?: 'Desconhecido', 255));
                self::el($w, 'City', self::txt($t['city'] ?: 'Desconhecido', 35));
                self::el($w, 'PostalCode', 'Desconhecido');
                self::el($w, 'Region', self::txt($t['city'] ?: 'Desconhecido', 50));
                self::el($w, 'Country', self::country($t['country']));
                $w->endElement();
                if (trim((string) $t['phone']) !== '') {
                    self::el($w, 'Telephone', self::txt($t['phone'], 18));
                }
                $mail = trim((string) $t['email']);
                if (filter_var($mail, FILTER_VALIDATE_EMAIL) && strlen($mail) <= 70) {
                    self::el($w, 'Email', $mail);
                }
                $w->endElement();
            }
        }

        if ($type === 'F') {
            $usedGeneric = false;
            foreach ($work as $d) {
                foreach ($db->all('SELECT item_id FROM sales_doc_lines WHERE doc_id = ?', [$d['id']]) as $l) {
                    $usedGeneric = $usedGeneric || !$l['item_id'];
                }
            }
            $items = $db->all('SELECT i.*, f.name AS family FROM items i LEFT JOIN item_families f ON f.id = i.family_id ORDER BY i.code');
            foreach ($items as $i) {
                self::product($w, $i['kind'] === 'S' ? 'S' : 'P', $i['code'], $i['family'], $i['name'], $i['barcode'] ?: $i['code'], $i['unit'] ?: 'EA');
            }
            if ($usedGeneric) {
                self::product($w, 'O', 'DIVERSOS', null, 'Artigos diversos (linhas sem artigo)', 'DIVERSOS', 'EA');
            }
        }

        $w->startElement('TaxTable');
        foreach ($db->all('SELECT * FROM vat_codes ORDER BY code') as $v) {
            $w->startElement('TaxTableEntry');
            self::el($w, 'TaxType', 'IVA');
            self::el($w, 'TaxCountryRegion', 'CV');
            self::el($w, 'TaxCode', $v['code']);
            self::el($w, 'Description', self::txt($v['name'], 255));
            self::el($w, 'TaxPercentage', number_format($v['rate_bp'] / 100, 2, '.', ''));
            $w->endElement();
        }
        $w->endElement();
        $w->endElement(); // MasterFiles

        // ---------------------------------------------------------------- GeneralLedgerEntries
        $entries = $db->all(
            "SELECT e.*, j.name AS journal_name, j.kind AS journal_kind FROM entries e JOIN journals j ON j.code = e.journal
             WHERE e.fiscal_year = ? AND e.status = 'validado' ORDER BY e.journal, e.number",
            [$year]
        );
        $totals = $db->one(
            "SELECT COALESCE(SUM(debit_cents),0) AS d, COALESCE(SUM(credit_cents),0) AS c FROM v_movements WHERE fiscal_year = ?",
            [$year]
        );
        $w->startElement('GeneralLedgerEntries');
        self::el($w, 'NumberOfEntries', (string) count($entries));
        self::el($w, 'TotalDebit', Money::decimal((int) $totals['d']));
        self::el($w, 'TotalCredit', Money::decimal((int) $totals['c']));
        $byJournal = [];
        foreach ($entries as $e) {
            $byJournal[$e['journal']][] = $e;
        }
        $linesSt = $db->pdo->prepare('SELECT l.*, t.kind AS tp_kind FROM entry_lines l LEFT JOIN third_parties t ON t.id = l.third_party WHERE l.entry_id = ? ORDER BY l.line_no');
        foreach ($byJournal as $code => $list) {
            $w->startElement('Journal');
            self::el($w, 'JournalID', str_replace(' ', '', (string) $code));
            self::el($w, 'Description', self::txt($list[0]['journal_name'], 255));
            foreach ($list as $e) {
                $linesSt->execute([$e['id']]);
                $lines = array_values(array_filter($linesSt->fetchAll(), static fn($l) => (int) $l['debit_cents'] > 0 || (int) $l['credit_cents'] > 0));
                $archival = self::archival($e['document'] ?: ($code . '/' . $e['number']));
                $month = (int) substr($e['date'], 5, 2);
                $w->startElement('Transaction');
                self::el($w, 'TransactionID', $e['date'] . ' ' . str_replace(' ', '', (string) $code) . ' ' . $e['number']);
                // períodos 1–12 = meses; 13 a 16 para operações de fim de exercício (apuramento de resultados)
                self::el($w, 'Period', (string) ($e['journal_kind'] === 'apuramento' && $month === 12 ? 13 : $month));
                self::el($w, 'TransactionDate', $e['date']);
                self::el($w, 'SourceID', 'IDEIA');
                self::el($w, 'Description', self::txt($e['description'], 255));
                self::el($w, 'DocArchivalNumber', $archival);
                self::el($w, 'TransactionType', $e['journal_kind'] === 'apuramento' ? 'A' : 'N');
                self::el($w, 'GLPostingDate', substr((string) $e['validated_at'], 0, 10) ?: $e['date']);
                foreach ($lines as $l) {
                    if ($l['third_party']) {
                        $isSupplier = $l['tp_kind'] === 'F';
                        self::el($w, $isSupplier ? 'SupplierID' : 'CustomerID', ($isSupplier ? 'F' : 'C') . $l['third_party']);
                        break;
                    }
                }
                $w->startElement('Lines');
                // o XSD exige primeiro todas as linhas a débito e depois as linhas a crédito
                foreach ([true, false] as $debitPass) {
                    foreach ($lines as $l) {
                        $isDebit = (int) $l['debit_cents'] > 0;
                        if ($isDebit !== $debitPass) {
                            continue;
                        }
                        $w->startElement($isDebit ? 'DebitLine' : 'CreditLine');
                        self::el($w, 'RecordID', $e['id'] . '-' . $l['line_no']);
                        self::el($w, 'AccountID', $l['account']);
                        self::el($w, 'SourceDocumentID', self::txt($e['document'] ?: ($code . '/' . $e['number']), 35));
                        self::el($w, 'SystemEntryDate', self::dt($e['validated_at'] ?: $e['date']));
                        self::el($w, 'Description', self::txt($l['description'] ?: $e['description'], 255));
                        self::el($w, $isDebit ? 'DebitAmount' : 'CreditAmount', Money::decimal($isDebit ? (int) $l['debit_cents'] : (int) $l['credit_cents']));
                        $w->endElement();
                    }
                }
                $w->endElement(); // Lines
                $w->endElement(); // Transaction
            }
            $w->endElement(); // Journal
        }
        $w->endElement(); // GeneralLedgerEntries

        if ($type === 'F') {
            $w->startElement('SourceDocuments');
            self::workingDocuments($w, $db, $work);
            self::payments($w, $db, $receipts, $supplierPayments);
            $w->endElement();
        }
        $w->endElement(); // AuditFile
        $w->endDocument();
        $db->audit('saft.exportado', ['year' => $year, 'tipo' => $type]);
        return $w->outputMemory();
    }

    /** Valida um SAF-T com o XSD oficial. Devolve a lista de erros (vazia se for válido). */
    public static function validate(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $errors = [];
        if (!$dom->loadXML($xml, LIBXML_PARSEHUGE)) {
            $errors[] = 'XML mal formado.';
        } elseif (!$dom->schemaValidate(self::XSD)) {
            $seen = [];
            foreach (libxml_get_errors() as $e) {
                $m = trim($e->message);
                if (!isset($seen[$m]) && count($seen) < 50) {
                    $seen[$m] = true;
                    $errors[] = $m . ' (linha ' . $e->line . ')';
                }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $errors;
    }

    private static function product(XMLWriter $w, string $type, string $code, ?string $group, string $name, string $number, string $unit): void
    {
        $w->startElement('Product');
        self::el($w, 'ProductType', $type);
        self::el($w, 'ProductCode', self::txt($code, 70));
        if ($group) {
            self::el($w, 'ProductGroup', self::txt($group, 70));
        }
        self::el($w, 'ProductDescription', self::desc($name));
        self::el($w, 'ProductNumberCode', self::txt($number, 70));
        self::el($w, 'UOMBase', self::txt($unit, 9));
        self::el($w, 'UOMStandard', self::txt($unit, 9));
        self::el($w, 'UOMToUOMBaseConversionFactor', '1');
        $w->endElement();
    }

    /** Orçamentos e faturas pró-forma (WorkingDocuments). */
    private static function workingDocuments(XMLWriter $w, Db $db, array $docs): void
    {
        $valid = array_filter($docs, static fn($d) => $d['status'] !== 'anulado');
        $w->startElement('WorkingDocuments');
        self::el($w, 'NumberOfEntries', (string) count($docs));
        self::el($w, 'TotalDebit', '0.00');
        self::el($w, 'TotalCredit', Money::decimal(array_sum(array_map(static fn($d) => (int) $d['net_cents'], $valid))));
        foreach ($docs as $d) {
            $invoiced = (bool) $db->value("SELECT 1 FROM sales_docs WHERE origin_id = ? AND type IN ('FT','FR','TV') AND status = 'emitido'", [$d['id']]);
            $w->startElement('WorkDocument');
            self::el($w, 'DocumentNumber', self::docNumber($d['type'], (string) $d['series'], (int) $d['number']));
            $w->startElement('DocumentStatus');
            self::el($w, 'WorkStatus', $d['status'] === 'anulado' ? 'A' : ($invoiced ? 'F' : 'N'));
            self::el($w, 'WorkStatusDate', self::dt($d['issued_at'] ?: $d['date']));
            if ($d['status'] === 'anulado' && $d['cancel_reason']) {
                self::el($w, 'Reason', self::txt($d['cancel_reason'], 35));
            }
            self::el($w, 'SourceID', 'IDEIA');
            self::el($w, 'SourceBilling', 'P');
            $w->endElement();
            self::el($w, 'Period', (string) (int) substr($d['date'], 5, 2));
            self::el($w, 'WorkDate', $d['date']);
            self::el($w, 'WorkType', $d['type']);
            self::el($w, 'SourceID', 'IDEIA');
            self::el($w, 'SystemEntryDate', self::dt($d['issued_at'] ?: $d['date']));
            self::el($w, 'CustomerID', 'C' . $d['third_party']);
            foreach ($db->all('SELECT l.*, i.code AS item_code FROM sales_doc_lines l LEFT JOIN items i ON i.id = l.item_id WHERE l.doc_id = ? ORDER BY l.line_no', [$d['id']]) as $l) {
                $w->startElement('Line');
                self::el($w, 'LineNumber', (string) $l['line_no']);
                self::el($w, 'ProductCode', self::txt($l['item_id'] ? $l['item_code'] : 'DIVERSOS', 70));
                self::el($w, 'ProductDescription', self::desc($l['description']));
                self::el($w, 'Quantity', rtrim(rtrim(sprintf('%.3f', $l['qty_milli'] / 1000), '0'), '.'));
                self::el($w, 'UnitOfMeasure', self::txt($l['unit'] ?: 'EA', 9));
                self::el($w, 'UnitPrice', Money::decimal((int) $l['price_cents']));
                self::el($w, 'TaxPointDate', $d['date']);
                self::el($w, 'Description', self::txt($l['description'], 255));
                self::el($w, 'CreditAmount', Money::decimal((int) $l['net_cents']));
                $w->startElement('Tax');
                self::el($w, 'TaxType', 'IVA');
                self::el($w, 'TaxCountryRegion', 'CV');
                self::el($w, 'TaxCode', (string) ($l['vat_code'] ?: 'NOR'));
                self::el($w, 'TaxPercentage', number_format($l['vat_rate_bp'] / 100, 2, '.', ''));
                $w->endElement();
                if ((int) $l['vat_rate_bp'] === 0 && $l['exemption']) {
                    $code = Efatura::exemptionCode($l['exemption']);
                    self::el($w, 'TaxExemptionReason', self::txt(Efatura::exemptionLabel($l['exemption']), 70));
                    if ($code) {
                        self::el($w, 'TaxExemptionCode', $code);
                    }
                }
                $w->endElement();
            }
            $w->startElement('DocumentTotals');
            self::el($w, 'TaxPayable', Money::decimal((int) $d['vat_cents']));
            self::el($w, 'NetTotal', Money::decimal((int) $d['net_cents']));
            self::el($w, 'GrossTotal', Money::decimal((int) $d['total_cents']));
            $w->endElement();
            $w->endElement();
        }
        $w->endElement();
    }

    /** Recibos de clientes (RC) e pagamentos a fornecedores (PF). */
    private static function payments(XMLWriter $w, Db $db, array $receipts, array $supplierPayments): void
    {
        $count = count($receipts) + count($supplierPayments);
        if ($count === 0) {
            return;
        }
        $ok = static fn($d) => $d['status'] !== 'anulado';
        $w->startElement('Payments');
        self::el($w, 'NumberOfEntries', (string) $count);
        self::el($w, 'TotalDebit', Money::decimal(array_sum(array_map(static fn($d) => (int) $d['total_cents'], array_filter($supplierPayments, $ok)))));
        self::el($w, 'TotalCredit', Money::decimal(array_sum(array_map(static fn($d) => (int) $d['total_cents'], array_filter($receipts, $ok)))));
        foreach ($receipts as $d) {
            $lines = $db->all('SELECT s.amount_cents, i.doc_no, i.date FROM sales_settlements s JOIN sales_docs i ON i.id = s.invoice_id WHERE s.receipt_id = ?', [$d['id']]);
            self::payment($w, $d, 'RC', self::docNumber('RC', (string) $d['series'], (int) $d['number']), 'CustomerID', 'C' . $d['third_party'],
                array_map(static fn($l) => [$l['doc_no'], $l['date'], (int) $l['amount_cents']], $lines), false, $d['issued_at'] ?? null);
        }
        foreach ($supplierPayments as $d) {
            $lines = $db->all('SELECT s.amount_cents, i.supplier_doc, i.doc_no, i.date FROM purchase_settlements s JOIN purchase_docs i ON i.id = s.invoice_id WHERE s.payment_id = ?', [$d['id']]);
            self::payment($w, $d, 'PF', self::docNumber('PG', (string) $d['series'], (int) $d['number']), 'SupplierID', 'F' . $d['third_party'],
                array_map(static fn($l) => [$l['supplier_doc'] ?: $l['doc_no'], $l['date'], (int) $l['amount_cents']], $lines), true, $d['registered_at'] ?? null);
        }
        $w->endElement();
    }

    private static function payment(XMLWriter $w, array $d, string $type, string $ref, string $partyTag, string $partyId, array $lines, bool $debit, ?string $at): void
    {
        $at = self::dt($at ?: $d['date']);
        $w->startElement('Payment');
        self::el($w, 'PaymentRefNo', $ref);
        self::el($w, 'Period', (string) (int) substr($d['date'], 5, 2));
        self::el($w, 'TransactionDate', $d['date']);
        self::el($w, 'PaymentType', $type);
        $w->startElement('DocumentStatus');
        self::el($w, 'PaymentStatus', $d['status'] === 'anulado' ? 'A' : 'N');
        self::el($w, 'PaymentStatusDate', $at);
        if ($d['status'] === 'anulado' && !empty($d['cancel_reason'])) {
            self::el($w, 'Reason', self::txt($d['cancel_reason'], 35));
        }
        self::el($w, 'SourceID', 'IDEIA');
        self::el($w, 'SourcePayment', 'P');
        $w->endElement();
        $w->startElement('PaymentMethod');
        self::el($w, 'PaymentMechanism', self::MECHANISMS[$d['payment_method'] ?? ''] ?? 'OU');
        self::el($w, 'PaymentAmount', Money::decimal((int) $d['total_cents']));
        self::el($w, 'PaymentDate', $d['date']);
        $w->endElement();
        self::el($w, 'SourceID', 'IDEIA');
        self::el($w, 'SystemEntryDate', $at);
        self::el($w, $partyTag, $partyId);
        foreach ($lines ?: [[$ref, $d['date'], (int) $d['total_cents']]] as $i => [$doc, $date, $amount]) {
            $w->startElement('Line');
            self::el($w, 'LineNumber', (string) ($i + 1));
            $w->startElement('SourceDocumentID');
            self::el($w, 'OriginatingON', self::txt($doc, 70));
            self::el($w, 'InvoiceDate', $date);
            $w->endElement();
            self::el($w, $debit ? 'DebitAmount' : 'CreditAmount', Money::decimal($amount));
            $w->endElement();
        }
        $w->startElement('DocumentTotals');
        self::el($w, 'TaxPayable', '0.00');
        self::el($w, 'NetTotal', Money::decimal((int) $d['total_cents']));
        self::el($w, 'GrossTotal', Money::decimal((int) $d['total_cents']));
        $w->endElement();
        $w->endElement();
    }

    /** N.º de documento no formato do XSD: tipo/série/número (ex.: OR/2025/3). */
    private static function docNumber(string $type, string $series, int $number): string
    {
        return $type . '/' . (str_replace('/', '-', $series) ?: '0') . '/' . $number;
    }

    /** DocArchivalNumber: sem espaços, até 20 carateres. */
    private static function archival(string $doc): string
    {
        return mb_substr(preg_replace('/\s+/u', '', $doc) ?: 'SN', 0, 20);
    }

    private static function dt(string $v): string
    {
        $v = trim($v);
        return strlen($v) <= 10 ? $v . 'T00:00:00' : str_replace(' ', 'T', substr($v, 0, 19));
    }

    private static function txt(?string $s, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s)), 0, $max);
    }

    private static function desc(?string $s): string
    {
        $t = self::txt($s, 255);
        return mb_strlen($t) >= 2 ? $t : str_pad($t, 2, '.');
    }

    private static function country(?string $c): string
    {
        $c = strtoupper(trim((string) $c));
        return preg_match('/^[A-Z]{2}$/', $c) ? $c : 'CV';
    }

    /** Saldos de abertura e de fecho por conta (todos os níveis), em centavos (débito − crédito). */
    private static function accountBalances(Db $db, int $year): array
    {
        $parents = [];
        foreach ($db->all('SELECT code, parent FROM accounts') as $a) {
            $parents[$a['code']] = $a['parent'];
        }
        $out = [];
        $rows = $db->all(
            "SELECT account,
                    SUM(CASE WHEN journal_kind = 'abertura' THEN debit_cents - credit_cents ELSE 0 END) AS o,
                    SUM(debit_cents - credit_cents) AS c
             FROM v_movements WHERE fiscal_year = ? GROUP BY account",
            [$year]
        );
        foreach ($rows as $r) {
            $code = $r['account'];
            while ($code !== null) {
                $out[$code] ??= ['open' => 0, 'close' => 0];
                $out[$code]['open'] += (int) $r['o'];
                $out[$code]['close'] += (int) $r['c'];
                $code = $parents[$code] ?? null;
            }
        }
        return $out;
    }

    private static function el(XMLWriter $w, string $name, string $value): void
    {
        $w->writeElement($name, $value);
    }
}
