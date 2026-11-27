<?php
declare(strict_types=1);

namespace Ideia\Saft;

use Ideia\Db;
use Ideia\Money;
use Ideia\UserError;
use XMLWriter;

/**
 * Exportação SAF-T (CV) — ficheiro de Contabilidade (C).
 *
 * A estrutura segue o modelo OCDE/SAF-T usado em Portugal (1.04), base do SAF-T de Cabo Verde
 * (Portaria n.º 47/2021). O namespace e a versão abaixo DEVEM ser confirmados contra o XSD oficial
 * publicado pela DNRE (saft.efatura.cv) e o ficheiro validado no SAFTPRO antes de qualquer entrega.
 */
final class Exporter
{
    public const NS = 'urn:OECD:StandardAuditFile-Tax:CV_1.0';
    public const VERSION = '1.0';

    public static function export(Db $db, int $year): string
    {
        $fy = $db->one('SELECT * FROM fiscal_years WHERE year = ?', [$year]);
        if (!$fy) {
            throw new UserError('Exercício inexistente.');
        }
        $nif = (string) $db->meta('company_nif');
        if (strlen($nif) !== 9) {
            throw new UserError('Indique o NIF da empresa (9 dígitos) nos dados da empresa antes de exportar o SAF-T.');
        }
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
        self::el($w, 'TaxAccountingBasis', 'C');
        self::el($w, 'CompanyName', (string) $db->meta('company_name'));
        $w->startElement('CompanyAddress');
        self::el($w, 'AddressDetail', $db->meta('company_address') ?: 'Desconhecido');
        self::el($w, 'City', $db->meta('company_city') ?: 'Desconhecido');
        self::el($w, 'Country', 'CV');
        $w->endElement();
        self::el($w, 'FiscalYear', (string) $year);
        self::el($w, 'StartDate', $fy['start_date']);
        self::el($w, 'EndDate', $fy['end_date']);
        self::el($w, 'CurrencyCode', 'CVE');
        self::el($w, 'DateCreated', date('Y-m-d'));
        self::el($w, 'TaxEntity', 'Global');
        self::el($w, 'ProductCompanyTaxID', '000000000');
        self::el($w, 'SoftwareCertificateNumber', '0');
        self::el($w, 'ProductID', 'IDEIA Contabilidade/IDEIA');
        self::el($w, 'ProductVersion', IDEIA_VERSION);
        $w->endElement();

        // ---------------------------------------------------------------- MasterFiles
        $w->startElement('MasterFiles');
        $w->startElement('GeneralLedgerAccounts');
        $balances = self::accountBalances($db, $year);
        $children = [];
        foreach ($db->all('SELECT code, parent FROM accounts WHERE parent IS NOT NULL') as $r) {
            $children[$r['parent']] = true;
        }
        foreach ($db->all('SELECT code, name, parent, level FROM accounts WHERE active = 1 OR code IN (SELECT DISTINCT account FROM entry_lines) ORDER BY code') as $a) {
            $b = $balances[$a['code']] ?? ['open' => 0, 'close' => 0];
            $w->startElement('Account');
            self::el($w, 'AccountID', $a['code']);
            self::el($w, 'AccountDescription', $a['name']);
            self::el($w, 'OpeningDebitBalance', Money::decimal(max($b['open'], 0)));
            self::el($w, 'OpeningCreditBalance', Money::decimal(max(-$b['open'], 0)));
            self::el($w, 'ClosingDebitBalance', Money::decimal(max($b['close'], 0)));
            self::el($w, 'ClosingCreditBalance', Money::decimal(max(-$b['close'], 0)));
            // GR = conta de 1.º grau, GA = agregadora, GM = de movimento
            $cat = $a['parent'] === null ? 'GR' : (isset($children[$a['code']]) ? 'GA' : 'GM');
            self::el($w, 'GroupingCategory', $cat);
            if ($a['parent'] !== null) {
                self::el($w, 'GroupingCode', $a['parent']);
            }
            $w->endElement();
        }
        $w->endElement();

        $parties = $db->all(
            'SELECT DISTINCT t.* FROM third_parties t
             WHERE t.id IN (SELECT third_party FROM entry_lines l JOIN entries e ON e.id = l.entry_id WHERE e.fiscal_year = ? AND e.status = \'validado\')
             ORDER BY t.id',
            [$year]
        );
        foreach (['C' => 'Customer', 'F' => 'Supplier'] as $kind => $tag) {
            foreach ($parties as $t) {
                if ($t['kind'] !== $kind && $t['kind'] !== 'A' && !($kind === 'C' && $t['kind'] === 'O')) {
                    continue;
                }
                $w->startElement($tag);
                self::el($w, $tag . 'ID', ($kind === 'C' ? 'C' : 'F') . $t['id']);
                self::el($w, 'AccountID', $t['account'] ?: 'Desconhecido');
                self::el($w, $tag . 'TaxID', $t['nif'] ?: '999999999');
                self::el($w, 'CompanyName', $t['name']);
                $w->startElement('BillingAddress');
                self::el($w, 'AddressDetail', $t['address'] ?: 'Desconhecido');
                self::el($w, 'City', $t['city'] ?: 'Desconhecido');
                self::el($w, 'Country', $t['country'] ?: 'CV');
                $w->endElement();
                self::el($w, 'SelfBillingIndicator', '0');
                $w->endElement();
            }
        }

        $w->startElement('TaxTable');
        foreach ($db->all('SELECT * FROM vat_codes ORDER BY code') as $v) {
            $w->startElement('TaxTableEntry');
            self::el($w, 'TaxType', 'IVA');
            self::el($w, 'TaxCountryRegion', 'CV');
            self::el($w, 'TaxCode', $v['code']);
            self::el($w, 'Description', $v['name']);
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
            self::el($w, 'JournalID', $code);
            self::el($w, 'Description', $list[0]['journal_name']);
            foreach ($list as $e) {
                $linesSt->execute([$e['id']]);
                $lines = $linesSt->fetchAll();
                $w->startElement('Transaction');
                self::el($w, 'TransactionID', $e['date'] . ' ' . $code . ' ' . $e['number']);
                self::el($w, 'Period', $e['journal_kind'] === 'abertura' ? '0' : (string) (int) substr($e['date'], 5, 2));
                self::el($w, 'TransactionDate', $e['date']);
                self::el($w, 'SourceID', 'IDEIA');
                self::el($w, 'Description', mb_substr($e['description'], 0, 200));
                self::el($w, 'DocArchivalNumber', $e['document'] ?: ($code . '/' . $e['number']));
                self::el($w, 'TransactionType', match ($e['journal_kind']) { 'apuramento' => 'A', 'abertura' => 'N', default => 'N' });
                self::el($w, 'GLPostingDate', substr((string) $e['validated_at'], 0, 10) ?: $e['date']);
                foreach ($lines as $l) {
                    if ($l['third_party']) {
                        $isSupplier = $l['tp_kind'] === 'F';
                        self::el($w, $isSupplier ? 'SupplierID' : 'CustomerID', ($isSupplier ? 'F' : 'C') . $l['third_party']);
                        break;
                    }
                }
                $w->startElement('Lines');
                foreach ($lines as $l) {
                    $isDebit = (int) $l['debit_cents'] > 0;
                    $w->startElement($isDebit ? 'DebitLine' : 'CreditLine');
                    self::el($w, 'RecordID', $e['id'] . '-' . $l['line_no']);
                    self::el($w, 'AccountID', $l['account']);
                    self::el($w, 'SourceDocumentID', $e['document'] ?: ($code . '/' . $e['number']));
                    self::el($w, 'SystemEntryDate', str_replace(' ', 'T', (string) $e['validated_at']));
                    self::el($w, 'Description', mb_substr($l['description'] ?: $e['description'], 0, 200));
                    self::el($w, $isDebit ? 'DebitAmount' : 'CreditAmount', Money::decimal($isDebit ? (int) $l['debit_cents'] : (int) $l['credit_cents']));
                    $w->endElement();
                }
                $w->endElement(); // Lines
                $w->endElement(); // Transaction
            }
            $w->endElement(); // Journal
        }
        $w->endElement(); // GeneralLedgerEntries
        $w->endElement(); // AuditFile
        $w->endDocument();
        $db->audit('saft.exportado', ['year' => $year]);
        return $w->outputMemory();
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
