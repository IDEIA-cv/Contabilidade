<?php
declare(strict_types=1);

namespace Ideia\Saft;

use Ideia\Db;
use Ideia\Ledger;
use Ideia\Money;
use Ideia\UserError;
use SimpleXMLElement;

/**
 * Importação de ficheiros SAF-T de contabilidade (CV ou PT 1.0x, mesma estrutura OCDE).
 * Serve para migrar o histórico de Primavera, PHC, Sage, Vendus e outros programas que produzem SAF-T.
 *
 * Fluxo: preview() analisa e mostra o que vai ser importado e os conflitos; commit() grava.
 */
final class Importer
{
    public static function load(string $xmlContent): SimpleXMLElement
    {
        if (str_starts_with($xmlContent, "\x1f\x8b")) {
            $xmlContent = (string) gzdecode($xmlContent);
        }
        // remove o namespace por omissão para simplificar as consultas (CV e PT usam namespaces diferentes)
        $head = substr($xmlContent, 0, 4096);
        $head = preg_replace('/(<AuditFile\b[^>]*?)\sxmlns="[^"]*"/', '$1', $head, 1) ?? $head;
        $xmlContent = $head . substr($xmlContent, 4096);
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($prev);
        if ($xml === false || $xml->getName() !== 'AuditFile') {
            throw new UserError('O ficheiro não é um SAF-T válido (falta o elemento AuditFile).');
        }
        if (!isset($xml->GeneralLedgerEntries) && !isset($xml->MasterFiles->GeneralLedgerAccounts)) {
            throw new UserError('Este SAF-T não tem contabilidade (GeneralLedgerAccounts / GeneralLedgerEntries). É provavelmente um SAF-T de faturação.');
        }
        return $xml;
    }

    /** Análise do ficheiro sem gravar nada. */
    public static function preview(Db $db, string $content): array
    {
        $xml = self::load($content);
        $h = $xml->Header;
        $year = (int) ($h->FiscalYear ?? 0);
        if ($year === 0 && isset($h->StartDate)) {
            $year = (int) substr((string) $h->StartDate, 0, 4);
        }
        $fileAccounts = self::fileAccounts($xml);
        $used = [];
        $nTx = 0;
        $td = 0;
        $tc = 0;
        $journals = [];
        $months = [];
        foreach ($xml->GeneralLedgerEntries->Journal ?? [] as $j) {
            $jid = (string) $j->JournalID;
            $journals[$jid] = ['code' => $jid, 'name' => (string) $j->Description, 'transactions' => 0];
            foreach ($j->Transaction as $t) {
                $nTx++;
                $journals[$jid]['transactions']++;
                $months[(int) substr((string) $t->TransactionDate, 5, 2)] = true;
                foreach ($t->Lines->DebitLine ?? [] as $l) {
                    $used[(string) $l->AccountID] = true;
                    $td += Money::toCents((string) $l->DebitAmount);
                }
                foreach ($t->Lines->CreditLine ?? [] as $l) {
                    $used[(string) $l->AccountID] = true;
                    $tc += Money::toCents((string) $l->CreditAmount);
                }
            }
        }
        // contas com saldo de abertura também têm de existir (para o lançamento de abertura)
        foreach (array_keys(self::leafOpenings($fileAccounts)) as $acc) {
            $used[(string) $acc] = true;
        }
        $declaredD = Money::toCents((string) ($xml->GeneralLedgerEntries->TotalDebit ?? '0'));
        $declaredC = Money::toCents((string) ($xml->GeneralLedgerEntries->TotalCredit ?? '0'));

        // contas: novas (a criar) e conflitos (existem mas têm subcontas no nosso plano => precisam de mapeamento)
        $new = [];
        $conflicts = [];
        foreach (array_keys($used) as $acc) {
            $acc = (string) $acc;
            $exists = $db->one('SELECT code FROM accounts WHERE code = ?', [$acc]);
            if (!$exists) {
                $new[] = ['code' => $acc, 'name' => $fileAccounts[$acc]['name'] ?? ('Conta ' . $acc)];
            } elseif (!Ledger::isMovementAccount($db, $acc)) {
                $conflicts[] = ['code' => $acc, 'name' => $fileAccounts[$acc]['name'] ?? '', 'reason' => 'No plano atual esta conta tem subcontas; escolha a subconta de destino.'];
            }
        }
        sort($new);
        $opening = 0;
        foreach (self::leafOpenings($fileAccounts) as $v) {
            $opening += abs($v);
        }
        $existingYear = $db->one('SELECT * FROM fiscal_years WHERE year = ?', [$year]);
        $existingEntries = $existingYear ? (int) $db->value('SELECT COUNT(*) FROM entries WHERE fiscal_year = ?', [$year]) : 0;
        $warnings = [];
        if ($td !== $tc) {
            $warnings[] = 'O total a débito difere do total a crédito no ficheiro.';
        }
        if ($declaredD && $declaredD !== $td) {
            $warnings[] = 'O TotalDebit declarado (' . Money::format($declaredD) . ') difere da soma das linhas (' . Money::format($td) . ').';
        }
        if ($existingEntries > 0) {
            $warnings[] = "O exercício {$year} já tem {$existingEntries} lançamento(s) nesta empresa. A importação só é permitida num exercício vazio.";
        }
        $hasOpeningJournal = false;
        foreach ($journals as $j) {
            if (preg_match('/abert|opening|^ab|^0/i', $j['code'] . ' ' . $j['name'])) {
                $hasOpeningJournal = true;
            }
        }
        $nif = (string) ($h->TaxRegistrationNumber ?? $h->CompanyID ?? '');
        $companyNif = (string) $db->meta('company_nif');
        if ($companyNif !== '' && $nif !== '' && preg_replace('/\D/', '', $nif) !== $companyNif) {
            $warnings[] = "O NIF do ficheiro ({$nif}) é diferente do NIF desta empresa ({$companyNif}).";
        }
        return [
            'company' => (string) ($h->CompanyName ?? ''),
            'nif' => $nif,
            'product' => trim((string) ($h->ProductID ?? '') . ' ' . (string) ($h->ProductVersion ?? '')),
            'version' => (string) ($h->AuditFileVersion ?? ''),
            'year' => $year,
            'start' => (string) ($h->StartDate ?? ''),
            'end' => (string) ($h->EndDate ?? ''),
            'accounts_in_file' => count($fileAccounts),
            'customers' => isset($xml->MasterFiles->Customer) ? count($xml->MasterFiles->Customer) : 0,
            'suppliers' => isset($xml->MasterFiles->Supplier) ? count($xml->MasterFiles->Supplier) : 0,
            'transactions' => $nTx,
            'journals' => array_values($journals),
            'months' => array_keys($months),
            'total_debit' => $td,
            'total_credit' => $tc,
            'new_accounts' => $new,
            'conflicts' => $conflicts,
            'opening_total' => $opening,
            'suggest_opening' => $opening > 0 && !$hasOpeningJournal,
            'can_import' => $existingEntries === 0 && $td === $tc && $year > 0,
            'warnings' => $warnings,
        ];
    }

    /**
     * Grava a importação. Opções: map {contaFicheiro: contaDestino}, create_opening (bool).
     */
    public static function commit(Db $db, string $content, array $options = [], string $filename = 'saft.xml'): array
    {
        $pre = self::preview($db, $content);
        if (!$pre['can_import']) {
            throw new UserError('Importação não permitida: ' . implode(' ', $pre['warnings']));
        }
        $map = array_filter(array_map('strval', (array) ($options['map'] ?? [])), static fn($v) => $v !== '');
        foreach ($pre['conflicts'] as $c) {
            if (empty($map[$c['code']])) {
                throw new UserError("Falta indicar a conta de destino para {$c['code']}.");
            }
        }
        $xml = self::load($content);
        $year = $pre['year'];
        $fileAccounts = self::fileAccounts($xml);

        return $db->transaction(static function () use ($db, $xml, $year, $fileAccounts, $map, $options, $pre, $content, $filename) {
            if (!$db->value('SELECT 1 FROM fiscal_years WHERE year = ?', [$year])) {
                Ledger::createFiscalYear($db, $year);
            }
            // 1. contas novas (das mais curtas para as mais longas, para a hierarquia ficar certa)
            $newCodes = array_column($pre['new_accounts'], 'name', 'code');
            uksort($newCodes, static fn($a, $b) => strlen((string) $a) <=> strlen((string) $b) ?: strcmp((string) $a, (string) $b));
            foreach ($newCodes as $code => $name) {
                $code = (string) $code;
                if (isset($map[$code])) {
                    continue;
                }
                // garantir contas-mãe intermédias que existam no ficheiro
                for ($i = 1; $i < strlen($code); $i++) {
                    $p = substr($code, 0, $i);
                    if (isset($fileAccounts[$p]) && !$db->value('SELECT 1 FROM accounts WHERE code = ?', [$p])) {
                        Ledger::saveAccount($db, ['code' => $p, 'name' => $fileAccounts[$p]['name']]);
                    }
                }
                if (!$db->value('SELECT 1 FROM accounts WHERE code = ?', [$code])) {
                    Ledger::saveAccount($db, ['code' => $code, 'name' => $name]);
                }
            }
            $acc = static fn(string $c) => $map[$c] ?? $c;

            // 2. terceiros
            $tpMap = [];
            foreach (['Customer' => 'C', 'Supplier' => 'F'] as $tag => $kind) {
                foreach ($xml->MasterFiles->{$tag} ?? [] as $t) {
                    $ext = (string) $t->{$tag . 'ID'};
                    $nif = preg_replace('/\D/', '', (string) $t->{$tag . 'TaxID'}) ?? '';
                    if ($nif === '999999999' || $nif === '000000000') {
                        $nif = '';
                    }
                    $country = (string) ($t->BillingAddress->Country ?? 'CV') ?: 'CV';
                    $existing = $nif !== '' ? $db->one('SELECT id FROM third_parties WHERE nif = ? AND kind = ?', [$nif, $kind]) : null;
                    if ($existing) {
                        $id = (int) $existing['id'];
                    } else {
                        $id = $db->insert('third_parties', [
                            'nif' => $nif ?: null,
                            'name' => (string) $t->CompanyName ?: $ext,
                            'kind' => $kind,
                            'address' => (string) ($t->BillingAddress->AddressDetail ?? '') ?: null,
                            'city' => (string) ($t->BillingAddress->City ?? '') ?: null,
                            'country' => strlen($country) === 2 ? strtoupper($country) : 'CV',
                            'account' => $db->value('SELECT code FROM accounts WHERE code = ?', [$acc((string) $t->AccountID)]),
                            'external_id' => $ext,
                        ]);
                    }
                    $tpMap[$tag . ':' . $ext] = $id;
                }
            }

            // 3. diários
            foreach ($pre['journals'] as $j) {
                if (!$db->value('SELECT 1 FROM journals WHERE code = ?', [$j['code']])) {
                    $db->insert('journals', ['code' => $j['code'], 'name' => $j['name'] ?: $j['code'], 'kind' => 'normal']);
                }
            }

            // 4. abertura a partir dos saldos iniciais das contas, se pedido
            $created = 0;
            if (!empty($options['create_opening'])) {
                $lines = [];
                foreach (self::leafOpenings($fileAccounts) as $code => $bal) {
                    if ($bal !== 0) {
                        $lines[] = ['account' => $acc((string) $code), 'debit_cents' => max($bal, 0), 'credit_cents' => max(-$bal, 0)];
                    }
                }
                if (count($lines) >= 2) {
                    Ledger::saveEntry($db, ['date' => "{$year}-01-01", 'journal' => 'ABE', 'description' => "Abertura {$year} (importada do SAF-T)", 'source' => 'saft', 'lines' => $lines, 'validate' => true]);
                    $created++;
                }
            }

            // 5. lançamentos
            foreach ($xml->GeneralLedgerEntries->Journal ?? [] as $j) {
                $jid = (string) $j->JournalID;
                foreach ($j->Transaction as $t) {
                    $tp = null;
                    if (isset($t->CustomerID)) {
                        $tp = $tpMap['Customer:' . (string) $t->CustomerID] ?? null;
                    } elseif (isset($t->SupplierID)) {
                        $tp = $tpMap['Supplier:' . (string) $t->SupplierID] ?? null;
                    }
                    $lines = [];
                    foreach ($t->Lines->children() as $l) {
                        $isDebit = $l->getName() === 'DebitLine';
                        $code = $acc((string) $l->AccountID);
                        $amount = Money::toCents((string) ($isDebit ? $l->DebitAmount : $l->CreditAmount));
                        $lines[] = [
                            'account' => $code,
                            'debit_cents' => $isDebit ? $amount : 0,
                            'credit_cents' => $isDebit ? 0 : $amount,
                            'description' => (string) $l->Description ?: null,
                            'third_party' => ($tp && preg_match('/^2[1-2]/', $code)) ? $tp : null,
                        ];
                    }
                    if (!$lines) {
                        continue;
                    }
                    $date = (string) $t->TransactionDate;
                    if (substr($date, 0, 4) !== (string) $year) {
                        throw new UserError("A transação {$t->TransactionID} tem data fora do exercício {$year}.");
                    }
                    Ledger::saveEntry($db, [
                        'date' => $date,
                        'journal' => $jid,
                        'description' => (string) $t->Description ?: ('Transação ' . (string) $t->TransactionID),
                        'document' => (string) ($t->DocArchivalNumber ?? '') ?: (string) $t->TransactionID,
                        'source' => 'saft',
                        'lines' => $lines,
                        'validate' => true,
                    ]);
                    $created++;
                }
            }
            $summary = ['year' => $year, 'entries' => $created, 'new_accounts' => count($pre['new_accounts']), 'third_parties' => count($tpMap), 'product' => $pre['product']];
            $db->insert('import_log', ['source' => 'saft', 'filename' => $filename, 'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'content' => gzencode($content, 6)]);
            $db->audit('importacao.saft', $summary);
            return $summary;
        });
    }

    /** Contas declaradas em MasterFiles: código => [name, open (débito − crédito), category]. */
    private static function fileAccounts(SimpleXMLElement $xml): array
    {
        $out = [];
        foreach ($xml->MasterFiles->GeneralLedgerAccounts->Account ?? [] as $a) {
            $code = (string) $a->AccountID;
            $out[$code] = [
                'name' => (string) $a->AccountDescription,
                'open' => Money::toCents((string) ($a->OpeningDebitBalance ?? '0')) - Money::toCents((string) ($a->OpeningCreditBalance ?? '0')),
                'category' => (string) ($a->GroupingCategory ?? ''),
            ];
        }
        return $out;
    }

    /** Saldos de abertura só das contas de movimento do ficheiro (GM, ou contas sem subcontas no ficheiro). */
    private static function leafOpenings(array $fileAccounts): array
    {
        $codes = array_map('strval', array_keys($fileAccounts));
        $out = [];
        foreach ($fileAccounts as $code => $a) {
            $code = (string) $code;
            $isLeaf = $a['category'] === 'GM';
            if ($a['category'] === '') {
                $isLeaf = true;
                foreach ($codes as $other) {
                    if ($other !== $code && str_starts_with($other, $code)) {
                        $isLeaf = false;
                        break;
                    }
                }
            }
            if ($isLeaf && $a['open'] !== 0) {
                $out[$code] = $a['open'];
            }
        }
        return $out;
    }
}
