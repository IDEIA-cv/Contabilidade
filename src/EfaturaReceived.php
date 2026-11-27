<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Documentos recebidos da e-Fatura: lê os DFE (XML, ou ZIP com vários XML) descarregados da Plataforma Eletrónica
 * em que a empresa é o adquirente, e cria as faturas de fornecedores correspondentes nas compras.
 *
 * FTE e NDE => fatura de fornecedor (FC); FRE e TVE => despesa paga (FP); NCE => nota de crédito (NF) da fatura
 * de origem. Recibos e guias não têm efeito nas compras e são ignorados. O IUD fica em purchase_docs.iud, o que
 * impede importar o mesmo documento duas vezes.
 */
final class EfaturaReceived
{
    private const TYPES = ['1' => 'FC', '2' => 'FP', '3' => 'FP', '5' => 'NF', '6' => 'FC'];
    private const NAMES = ['1' => 'Fatura', '2' => 'Fatura-recibo', '3' => 'Talão de venda', '4' => 'Recibo', '5' => 'Nota de crédito',
        '6' => 'Nota de débito', '7' => 'Documento de transporte', '8' => 'Nota de devolução', '9' => 'Nota de lançamento'];
    private const MEANS = ['10' => 'NU', '31' => 'TB', '30' => 'TB', '42' => 'TB', '48' => 'CC', '54' => 'CC', '55' => 'CC', '20' => 'CH'];
    private const MAX_FILES = 500;

    /** Extrai os XML de um ficheiro (XML ou ZIP). @return array<string,string> nome => conteúdo */
    public static function files(string $name, string $content): array
    {
        if (str_starts_with($content, "PK")) {
            if (!class_exists(\ZipArchive::class)) {
                throw new UserError('A extensão zip do PHP não está ativa: descompacte o ficheiro e envie os XML.');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'dfe');
            file_put_contents($tmp, $content);
            $z = new \ZipArchive();
            if ($z->open($tmp) !== true) {
                @unlink($tmp);
                throw new UserError('Não foi possível abrir o ZIP.');
            }
            $out = [];
            for ($i = 0; $i < $z->numFiles && count($out) < self::MAX_FILES; $i++) {
                $n = (string) $z->getNameIndex($i);
                $st = $z->statIndex($i);
                if (str_ends_with(strtolower($n), '.xml') && ($st['size'] ?? 0) < 5_000_000) {
                    $out[basename($n)] = (string) $z->getFromIndex($i);
                }
            }
            $z->close();
            @unlink($tmp);
            if (!$out) {
                throw new UserError('O ZIP não tem ficheiros XML.');
            }
            return $out;
        }
        if (!str_contains(substr($content, 0, 2000), '<')) {
            throw new UserError('O ficheiro não é um XML nem um ZIP de documentos da e-Fatura.');
        }
        return [$name => $content];
    }

    /** Lê um DFE e devolve os dados normalizados (sem gravar nada). */
    public static function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        // remove o namespace por omissão para simplificar as consultas
        $sx = simplexml_load_string(preg_replace('/\sxmlns="[^"]*"/', '', $xml, 1) ?? '', \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$sx || $sx->getName() !== 'Dfe') {
            throw new UserError('Não é um documento fiscal eletrónico (falta o elemento Dfe).');
        }
        $code = (string) $sx['DocumentTypeCode'];
        $iud = (string) $sx['Id'];
        $doc = null;
        foreach ($sx->children() as $c) {
            if (!in_array($c->getName(), ['Transmission', 'RepositoryCode', 'Signature'], true)) {
                $doc = $c;
                break;
            }
        }
        if (!$doc) {
            throw new UserError('Documento sem conteúdo.');
        }
        $dec = static fn($v) => Money::toCents(str_replace(',', '.', trim((string) $v)) ?: '0');
        $lines = [];
        foreach ($doc->Lines->Line ?? [] as $l) {
            $tax = $l->Tax;
            $disc = isset($l->Discount) && (string) ($l->Discount['ValueType'] ?? 'P') === 'P' ? (float) $l->Discount : 0.0;
            $lines[] = [
                'description' => trim((string) ($l->Item->Description ?? '')) ?: 'Linha ' . (string) $l->Id,
                'qty_milli' => (int) round(((float) (string) $l->Quantity) * 1000),
                'unit' => (string) ($l->Quantity['UnitCode'] ?? ''),
                'price_cents' => $dec($l->Price),
                'discount_bp' => (int) round($disc * 100),
                'net_cents' => $dec($l->NetTotal),
                'rate_bp' => (string) ($tax['TaxTypeCode'] ?? '') === 'IVA' ? (int) round(((float) (string) $tax->TaxPercentage) * 100) : 0,
                'exemption' => (string) ($tax->TaxExemptionReasonCode ?? ''),
            ];
        }
        $refs = [];
        foreach ($doc->References->Reference ?? [] as $r) {
            $refs[] = ['iud' => (string) ($r->FiscalDocument['IsOldDocument'] ?? 'false') === 'true' ? null : trim((string) $r->FiscalDocument),
                'doc' => trim((string) ($r->InnerDocumentNumber ?? '')) ?: trim((string) $r->FiscalDocument)];
        }
        $t = $doc->Totals;
        $emitter = $doc->EmitterParty;
        $receiver = $doc->ReceiverParty;
        return [
            'iud' => $iud,
            'type_code' => $code,
            'type_name' => self::NAMES[$code] ?? "Tipo {$code}",
            'purchase_type' => self::TYPES[$code] ?? null,
            'number' => trim((string) ($doc->InnerDocumentNumber ?? '')) ?: trim((string) $doc->Serie) . '/' . trim((string) $doc->DocumentNumber),
            'date' => (string) $doc->IssueDate,
            'due_date' => (string) ($doc->DueDate ?? $doc->Payments->PaymentDueDate ?? ''),
            'supplier' => [
                'nif' => preg_replace('/\s/', '', (string) $emitter->TaxId), 'country' => (string) ($emitter->TaxId['CountryCode'] ?? 'CV'),
                'name' => trim((string) $emitter->Name), 'address' => trim((string) ($emitter->Address->AddressDetail ?? '')),
                'city' => trim((string) ($emitter->Address->City ?? '')), 'email' => trim((string) ($emitter->Contacts->Email ?? '')),
                'phone' => trim((string) ($emitter->Contacts->Telephone ?? $emitter->Contacts->Mobilephone ?? '')),
            ],
            'receiver_nif' => preg_replace('/\s/', '', (string) ($receiver->TaxId ?? '')),
            'lines' => $lines,
            'net_cents' => isset($t) ? $dec($t->NetTotalAmount) : array_sum(array_column($lines, 'net_cents')),
            'vat_cents' => isset($t) ? $dec($t->TaxTotalAmount) : 0,
            'withholding_cents' => isset($t) ? $dec($t->WithholdingTaxTotalAmount ?? 0) : 0,
            'total_cents' => isset($t) ? $dec($t->PayableAmount) : 0,
            'payment_method' => self::MEANS[(string) ($doc->Payments->Payment->PaymentMeansCode ?? '')] ?? 'OU',
            'references' => $refs,
            'issue_mode' => (string) ($sx->Transmission->IssueMode ?? ''),
        ];
    }

    /** Pré-visualização: o que acontece a cada documento se for importado. */
    public static function preview(Db $db, string $name, string $content): array
    {
        $rows = [];
        foreach (self::files($name, $content) as $file => $xml) {
            $rows[] = self::analyse($db, $file, $xml);
        }
        $count = static fn(string $s) => count(array_filter($rows, static fn($r) => $r['status'] === $s));
        return ['rows' => array_map(static fn($r) => array_diff_key($r, ['data' => 1]), $rows),
            'counts' => ['novo' => $count('novo'), 'duplicado' => $count('duplicado'), 'ignorado' => $count('ignorado'), 'erro' => $count('erro')]];
    }

    /**
     * Importa os documentos novos. opts: register (registar logo quando o fornecedor já tem conta habitual de gastos),
     * account (conta a usar quando o fornecedor não tem histórico; vazio = fica em rascunho com a conta 6268).
     */
    public static function import(Db $db, string $name, string $content, array $opts = []): array
    {
        $register = !empty($opts['register']) && !in_array($opts['register'], ['0', 'false'], true);
        $results = [];
        foreach (self::files($name, $content) as $file => $xml) {
            $r = self::analyse($db, $file, $xml);
            if ($r['status'] !== 'novo') {
                $results[] = array_diff_key($r, ['data' => 1]);
                continue;
            }
            try {
                $doc = $db->transaction(static fn() => self::create($db, $r['data'], $register));
                $r['message'] = str_replace('Fornecedor novo: será criado.', 'Fornecedor criado.', $r['message']);
                $results[] = array_diff_key($r, ['data' => 1]) + ['created_id' => $doc['id'], 'created_status' => $doc['status'], 'created' => $doc['doc_no'] ?? null];
            } catch (UserError $e) {
                $results[] = ['status' => 'erro', 'message' => $e->getMessage()] + array_diff_key($r, ['data' => 1]);
            }
        }
        $created = array_filter($results, static fn($x) => !empty($x['created_id']));
        $db->insert('import_log', ['source' => 'e-Fatura (recebidos)', 'filename' => $name,
            'summary' => count($created) . ' importado(s), ' . (count($results) - count($created)) . ' ignorado(s)/duplicado(s)/com erro']);
        $db->audit('efatura.recebidos', ['ficheiro' => $name, 'importados' => count($created)]);
        return ['rows' => $results, 'created' => count($created)];
    }

    private static function analyse(Db $db, string $file, string $xml): array
    {
        $base = ['file' => $file, 'iud' => null, 'type_name' => '', 'number' => '', 'date' => '', 'supplier' => '', 'supplier_nif' => '', 'supplier_exists' => false,
            'total_cents' => 0, 'vat_cents' => 0, 'purchase_type' => null];
        try {
            $d = self::parse($xml);
        } catch (UserError $e) {
            return ['status' => 'erro', 'message' => $e->getMessage()] + $base;
        }
        $nif = (string) $db->meta('company_nif');
        $sup = $d['supplier']['nif'] !== '' ? $db->one('SELECT id, name, kind FROM third_parties WHERE nif = ? ORDER BY (kind IN (\'F\',\'A\')) DESC LIMIT 1', [$d['supplier']['nif']]) : null;
        $row = ['iud' => $d['iud'], 'type_name' => $d['type_name'], 'number' => $d['number'], 'date' => $d['date'], 'supplier' => $d['supplier']['name'],
            'supplier_nif' => $d['supplier']['nif'], 'supplier_exists' => (bool) $sup, 'total_cents' => $d['total_cents'], 'vat_cents' => $d['vat_cents'],
            'purchase_type' => $d['purchase_type'], 'data' => $d] + $base;
        $st = static fn(string $s, string $m) => ['status' => $s, 'message' => $m] + $row;
        if ($d['supplier']['nif'] === $nif) {
            return $st('ignorado', 'Documento emitido por esta empresa (é uma venda, não uma compra).');
        }
        if ($d['receiver_nif'] !== '' && $d['receiver_nif'] !== $nif) {
            return $st('ignorado', "O adquirente é o NIF {$d['receiver_nif']}, não esta empresa.");
        }
        if (!$d['purchase_type']) {
            return $st('ignorado', $d['type_name'] . ' sem efeito nas compras.');
        }
        if ($d['iud'] !== '' && $db->value('SELECT 1 FROM purchase_docs WHERE iud = ?', [$d['iud']])) {
            return $st('duplicado', 'Já importado.');
        }
        if ($sup && $db->value("SELECT 1 FROM purchase_docs WHERE third_party = ? AND supplier_doc = ? AND status <> 'anulado'", [$sup['id'], $d['number']])) {
            return $st('duplicado', 'Já registado manualmente com o mesmo número.');
        }
        if (!$db->value('SELECT 1 FROM fiscal_years WHERE ? BETWEEN start_date AND end_date', [$d['date']])) {
            return $st('erro', 'Não existe exercício para a data ' . $d['date'] . '.');
        }
        if (!$d['lines']) {
            return $st('erro', 'Documento sem linhas.');
        }
        if ($d['purchase_type'] === 'NF' && !self::origin($db, $d, $sup ? (int) $sup['id'] : 0)) {
            return $st('erro', 'Nota de crédito: importe ou registe primeiro a fatura de origem (' . implode(', ', array_column($d['references'], 'doc')) . ').');
        }
        foreach ($d['lines'] as $l) {
            if ($l['rate_bp'] > 0 && !self::vatCode($db, $l['rate_bp'])) {
                return $st('erro', 'Não há código de IVA dedutível à taxa de ' . ($l['rate_bp'] / 100) . '% no plano (Empresa e cópias).');
            }
        }
        $msg = $sup ? 'Fornecedor existente.' : 'Fornecedor novo: será criado.';
        if ($d['withholding_cents']) {
            $msg .= ' Tem retenção na fonte de ' . Money::format($d['withholding_cents']) . ': confirme o lançamento.';
        }
        return $st('novo', $msg);
    }

    private static function origin(Db $db, array $d, int $party): ?array
    {
        foreach ($d['references'] as $r) {
            if ($r['iud'] && ($o = $db->one("SELECT * FROM purchase_docs WHERE iud = ? AND type = 'FC' AND status = 'registado'", [$r['iud']]))) {
                return $o;
            }
            if ($party && $r['doc'] !== '' && ($o = $db->one("SELECT * FROM purchase_docs WHERE third_party = ? AND supplier_doc = ? AND type = 'FC' AND status = 'registado'", [$party, $r['doc']]))) {
                return $o;
            }
        }
        return null;
    }

    private static function vatCode(Db $db, int $rate): ?string
    {
        $c = $db->value("SELECT code FROM vat_codes WHERE kind = 'dedutivel' AND rate_bp = ? ORDER BY code = 'DED' DESC LIMIT 1", [$rate]);
        return $c ? (string) $c : null;
    }

    private static function create(Db $db, array $d, bool $register): array
    {
        $s = $d['supplier'];
        $party = $db->one('SELECT id, kind FROM third_parties WHERE nif = ? ORDER BY (kind IN (\'F\',\'A\')) DESC LIMIT 1', [$s['nif']]);
        if (!$party) {
            $id = $db->insert('third_parties', ['nif' => $s['nif'], 'name' => mb_substr($s['name'] ?: 'Fornecedor ' . $s['nif'], 0, 150), 'kind' => 'F',
                'address' => $s['address'] ?: null, 'city' => $s['city'] ?: null, 'country' => preg_match('/^[A-Z]{2}$/', $s['country']) ? $s['country'] : 'CV',
                'email' => $s['email'] ?: null, 'phone' => $s['phone'] ?: null]);
            $db->audit('terceiro.criado', ['id' => $id, 'origem' => 'e-Fatura']);
        } else {
            $id = (int) $party['id'];
            if ($party['kind'] === 'C') {
                $db->run("UPDATE third_parties SET kind = 'A' WHERE id = ?", [$id]);
            }
        }
        $last = Purchases::lastAccount($db, $id);
        $exempt = (string) ($db->value("SELECT code FROM vat_codes WHERE kind IN ('isento','dedutivel') AND rate_bp = 0 ORDER BY code = 'ISE' DESC LIMIT 1") ?: 'ISE');
        $mk = static fn(array $l, bool $exact) => [
            'description' => mb_substr($l['description'], 0, 255),
            'qty_milli' => $exact ? 1000 : max(1, $l['qty_milli']),
            'price_cents' => $exact ? $l['net_cents'] : $l['price_cents'],
            'discount_bp' => $exact ? 0 : $l['discount_bp'],
            'vat_code' => $l['rate_bp'] > 0 ? self::vatCode($db, $l['rate_bp']) : $exempt,
            'account' => $last ?: '',
        ];
        $lines = array_map(static fn($l) => $mk($l, false), $d['lines']);
        // se o cálculo pela quantidade × preço não der o mesmo valor líquido, usa o valor líquido de cada linha
        $net = 0;
        foreach ($lines as $i => $l) {
            [$n] = Sales::lineAmounts($l['qty_milli'], $l['price_cents'], $l['discount_bp'], 0);
            if ($n !== $d['lines'][$i]['net_cents']) {
                $lines = array_map(static fn($l) => $mk($l, true), $d['lines']);
                break;
            }
        }
        $origin = $d['purchase_type'] === 'NF' ? self::origin($db, $d, $id) : null;
        $note = 'Importado da e-Fatura. IUD ' . $d['iud'];
        $doc = Purchases::save($db, [
            'type' => $d['purchase_type'], 'date' => $d['date'],
            'due_date' => $d['purchase_type'] === 'FC' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['due_date']) && $d['due_date'] >= $d['date'] ? $d['due_date'] : '',
            'third_party' => $id, 'supplier_doc' => mb_substr($d['number'], 0, 60), 'payment_method' => $d['purchase_type'] === 'FP' ? $d['payment_method'] : '',
            'origin_id' => $origin['id'] ?? null, 'notes' => $note, 'lines' => $lines,
        ]);
        $db->run('UPDATE purchase_docs SET iud = ? WHERE id = ?', [$d['iud'] ?: null, $doc['id']]);
        if ($register && $last) {
            $doc = Purchases::register($db, (int) $doc['id']);
        }
        return $doc;
    }
}
