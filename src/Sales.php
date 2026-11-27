<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Vendas: artigos e documentos comerciais (orçamentos, pro-formas, guias, faturas, faturas-recibo, notas de débito e
 * de crédito, recibos). Numeração automática sem saltos por tipo e série, cadeia de hash por série, e lançamento
 * contabilístico automático dos documentos com efeito nas contas.
 *
 * A comunicação à DNRE (e-Fatura) ainda não está ligada: os documentos fiscais ficam com efatura_status = 'pendente'.
 */
final class Sales
{
    /**
     * name: nome; short: nome curto; party: exige cliente; accounting: gera lançamento; sign: +1 venda, -1 crédito;
     * efatura: tipo de documento eletrónico na e-Fatura (comunicação pendente); due: dias até ao vencimento/validade.
     */
    public const TYPES = [
        'OR' => ['name' => 'Orçamento', 'plural' => 'Orçamentos', 'party' => true, 'accounting' => false, 'sign' => 0, 'efatura' => null, 'due' => 30, 'due_label' => 'Válido até'],
        'PF' => ['name' => 'Fatura pró-forma', 'plural' => 'Faturas pró-forma', 'party' => true, 'accounting' => false, 'sign' => 0, 'efatura' => null, 'due' => 15, 'due_label' => 'Válida até'],
        'GE' => ['name' => 'Guia de entrega', 'plural' => 'Guias de entrega', 'party' => true, 'accounting' => false, 'sign' => 0, 'efatura' => 'DTE', 'due' => 0, 'due_label' => ''],
        'GT' => ['name' => 'Guia de transporte', 'plural' => 'Guias de transporte', 'party' => true, 'accounting' => false, 'sign' => 0, 'efatura' => 'DTE', 'due' => 0, 'due_label' => ''],
        'FT' => ['name' => 'Fatura', 'plural' => 'Faturas', 'party' => true, 'accounting' => true, 'sign' => 1, 'efatura' => 'FTE', 'due' => 30, 'due_label' => 'Vencimento'],
        'FR' => ['name' => 'Fatura-recibo', 'plural' => 'Faturas-recibo', 'party' => false, 'accounting' => true, 'sign' => 1, 'efatura' => 'FRE', 'due' => 0, 'due_label' => ''],
        'ND' => ['name' => 'Nota de débito', 'plural' => 'Notas de débito', 'party' => true, 'accounting' => true, 'sign' => 1, 'efatura' => 'NDE', 'due' => 30, 'due_label' => 'Vencimento'],
        'NC' => ['name' => 'Nota de crédito', 'plural' => 'Notas de crédito', 'party' => true, 'accounting' => true, 'sign' => -1, 'efatura' => 'NCE', 'due' => 0, 'due_label' => ''],
        'RC' => ['name' => 'Recibo', 'plural' => 'Recibos', 'party' => true, 'accounting' => true, 'sign' => 0, 'efatura' => 'RCE', 'due' => 0, 'due_label' => ''],
    ];

    /** Para que tipos se pode converter cada documento. */
    public const CONVERT = [
        'OR' => ['FT', 'FR', 'PF', 'GE'],
        'PF' => ['FT', 'FR', 'GE'],
        'GE' => ['FT', 'FR'],
        'GT' => ['FT', 'FR'],
        'FT' => ['GE', 'GT'],
    ];

    public const PAYMENT_METHODS = ['NU' => 'Numerário', 'TB' => 'Transferência bancária', 'CC' => 'Cartão', 'CH' => 'Cheque', 'OU' => 'Outro'];

    /** Motivos de isenção mais usados (texto livre também é aceite). */
    public const EXEMPTIONS = [
        'Isento nos termos do artigo 9.º do RIVA',
        'Não sujeito — operação fora do âmbito do IVA',
        'Exportação de bens — isenção (artigo 14.º do RIVA)',
        'Regime especial dos pequenos contribuintes (REMPE)',
    ];

    // ================================================================== artigos

    public static function items(Db $db, array $f = []): array
    {
        $where = ['1=1'];
        $p = [];
        if (($f['q'] ?? '') !== '') {
            $where[] = '(i.code LIKE ? OR i.name LIKE ? OR i.barcode = ?)';
            $q = '%' . $f['q'] . '%';
            array_push($p, $q, $q, $f['q']);
        }
        if (($f['kind'] ?? '') !== '') {
            $where[] = 'i.kind = ?';
            $p[] = $f['kind'];
        }
        if (($f['active'] ?? '1') !== 'all') {
            $where[] = 'i.active = ?';
            $p[] = ($f['active'] ?? '1') === '0' ? 0 : 1;
        }
        return $db->all(
            'SELECT i.*, v.rate_bp, v.name AS vat_name,
                    (SELECT COUNT(*) FROM sales_doc_lines l WHERE l.item_id = i.id) AS uses
             FROM items i LEFT JOIN vat_codes v ON v.code = i.vat_code
             WHERE ' . implode(' AND ', $where) . ' ORDER BY i.name',
            $p
        );
    }

    public static function saveItem(Db $db, array $d): array
    {
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new UserError('Indique a descrição do artigo.');
        }
        $kind = in_array($d['kind'] ?? 'P', ['P', 'S'], true) ? ($d['kind'] ?? 'P') : 'P';
        $id = !empty($d['id']) ? (int) $d['id'] : null;
        if ($code === '') {
            // código automático: P0001 / S0001
            $n = (int) $db->value("SELECT COUNT(*) FROM items WHERE code LIKE ?", [$kind . '%']) + 1;
            do {
                $code = $kind . str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
            } while ($db->value('SELECT 1 FROM items WHERE code = ?', [$code]));
        }
        if (strlen($code) > 30) {
            throw new UserError('O código do artigo tem no máximo 30 caracteres.');
        }
        if ($db->value('SELECT 1 FROM items WHERE code = ? AND id IS NOT ?', [$code, $id])) {
            throw new UserError("Já existe um artigo com o código {$code}.");
        }
        $price = isset($d['price_cents']) ? (int) $d['price_cents'] : Money::toCents((string) ($d['price'] ?? '0'));
        if ($price < 0) {
            throw new UserError('O preço não pode ser negativo.');
        }
        $vat = (string) ($d['vat_code'] ?? 'NOR');
        $vatRow = self::saleVat($db, $vat);
        $exemption = trim((string) ($d['exemption'] ?? ''));
        if ((int) $vatRow['rate_bp'] === 0 && $exemption === '') {
            throw new UserError('Indique o motivo de isenção do IVA.');
        }
        $account = preg_replace('/\D/', '', (string) ($d['account'] ?? '')) ?: ($kind === 'S' ? '721' : '711');
        self::requireRevenueAccount($db, $account);
        $row = [
            'code' => $code, 'name' => $name, 'kind' => $kind,
            'unit' => strtoupper(trim((string) ($d['unit'] ?? ''))) ?: ($kind === 'S' ? 'SERV' : 'UN'),
            'price_cents' => $price, 'vat_code' => $vat, 'exemption' => (int) $vatRow['rate_bp'] === 0 ? $exemption : null,
            'account' => $account, 'barcode' => trim((string) ($d['barcode'] ?? '')) ?: null,
            'active' => isset($d['active']) && in_array($d['active'], [false, 0, '0', 'false'], true) ? 0 : 1,
        ];
        if ($id) {
            $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($row)));
            $db->run("UPDATE items SET $sets WHERE id = ?", [...array_values($row), $id]);
        } else {
            $id = $db->insert('items', $row);
        }
        $db->audit('artigo.guardado', ['id' => $id, 'code' => $code]);
        return $db->one('SELECT * FROM items WHERE id = ?', [$id]);
    }

    /** Apaga um artigo nunca usado; se já foi usado, fica apenas inativo. */
    public static function deleteItem(Db $db, int $id): string
    {
        if ($db->value('SELECT 1 FROM sales_doc_lines WHERE item_id = ?', [$id])) {
            $db->run('UPDATE items SET active = 0 WHERE id = ?', [$id]);
            return 'inativo';
        }
        $db->run('DELETE FROM items WHERE id = ?', [$id]);
        return 'apagado';
    }

    private static function saleVat(Db $db, string $code): array
    {
        $v = $db->one('SELECT * FROM vat_codes WHERE code = ?', [$code]);
        if (!$v || !in_array($v['kind'], ['liquidado', 'isento'], true)) {
            throw new UserError('Escolha uma taxa de IVA de vendas (liquidado ou isento).');
        }
        return $v;
    }

    private static function requireRevenueAccount(Db $db, string $account): void
    {
        if (!$db->value('SELECT 1 FROM accounts WHERE code = ?', [$account])) {
            throw new UserError("A conta {$account} não existe no plano de contas.");
        }
        if (!Ledger::isMovementAccount($db, $account)) {
            throw new UserError("A conta {$account} tem subcontas. Escolha uma conta de movimento (por exemplo 711 ou 721).");
        }
    }

    // ================================================================== documentos

    public static function typeInfo(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new UserError('Tipo de documento desconhecido.');
        }
        return self::TYPES[$type];
    }

    /** Lista com filtros. f: type (um tipo ou "faturas"), status, pay (aberto|pago|vencido), party, q, from, to, limit, offset. */
    public static function list(Db $db, array $f = []): array
    {
        $where = ['1=1'];
        $p = [];
        $type = (string) ($f['type'] ?? '');
        if ($type === 'faturas') {
            $where[] = "d.type IN ('FT','FR','ND','NC')";
        } elseif ($type !== '') {
            self::typeInfo($type);
            $where[] = 'd.type = ?';
            $p[] = $type;
        }
        if (($f['status'] ?? '') !== '') {
            $where[] = 'd.status = ?';
            $p[] = $f['status'];
        }
        if (!empty($f['party'])) {
            $where[] = 'd.third_party = ?';
            $p[] = (int) $f['party'];
        }
        if (($f['from'] ?? '') !== '') {
            $where[] = 'd.date >= ?';
            $p[] = $f['from'];
        }
        if (($f['to'] ?? '') !== '') {
            $where[] = 'd.date <= ?';
            $p[] = $f['to'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(d.doc_no LIKE ? OR COALESCE(t.name, d.customer_name) LIKE ? OR COALESCE(t.nif, d.customer_nif) LIKE ? OR d.reference LIKE ?)';
            $q = '%' . $f['q'] . '%';
            array_push($p, $q, $q, $q, $q);
        }
        $open = self::openSql();
        $pay = (string) ($f['pay'] ?? '');
        if ($pay !== '') {
            $where[] = "d.type IN ('FT','ND') AND d.status = 'emitido'";
            $where[] = match ($pay) {
                'aberto' => "($open) > 0",
                'vencido' => "($open) > 0 AND d.due_date < date('now','localtime')",
                'pago' => "($open) <= 0",
                default => '1=1',
            };
        }
        $base = 'FROM sales_docs d LEFT JOIN third_parties t ON t.id = d.third_party WHERE ' . implode(' AND ', $where);
        $limit = max(0, (int) ($f['limit'] ?? 100));
        $offset = max(0, (int) ($f['offset'] ?? 0));
        $sums = $db->one(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN d.status = 'emitido' AND d.type IN ('FT','FR','ND') THEN d.total_cents
                                      WHEN d.status = 'emitido' AND d.type = 'NC' THEN -d.total_cents ELSE 0 END), 0) AS invoiced,
                    COALESCE(SUM(CASE WHEN d.status = 'emitido' AND d.type IN ('FT','ND') THEN ($open) ELSE 0 END), 0) AS open
             $base",
            $p
        );
        $rows = $db->all(
            "SELECT d.id, d.type, d.doc_no, d.status, d.date, d.due_date, d.total_cents, d.vat_cents, d.net_cents, d.reference,
                    d.efatura_status, d.origin_id, d.third_party,
                    COALESCE(t.name, d.customer_name) AS customer, COALESCE(t.nif, d.customer_nif) AS nif,
                    CASE WHEN d.type IN ('FT','ND') AND d.status = 'emitido' THEN ($open) END AS open_cents,
                    (SELECT GROUP_CONCAT(x.doc_no, ', ') FROM sales_docs x WHERE x.origin_id = d.id AND x.status <> 'anulado' AND x.doc_no IS NOT NULL) AS derived
             $base ORDER BY d.date DESC, d.id DESC" . ($limit ? " LIMIT $limit OFFSET $offset" : ''),
            $p
        );
        return ['rows' => $rows, 'total' => (int) $sums['n'], 'invoiced' => (int) $sums['invoiced'], 'open' => (int) $sums['open'], 'limit' => $limit, 'offset' => $offset];
    }

    /** SQL (correlacionado com d) do valor em dívida de uma fatura / nota de débito. */
    private static function openSql(): string
    {
        return "d.total_cents
            - COALESCE((SELECT SUM(s.amount_cents) FROM sales_settlements s JOIN sales_docs r ON r.id = s.receipt_id
                        WHERE s.invoice_id = d.id AND r.status = 'emitido'), 0)
            - COALESCE((SELECT SUM(n.total_cents) FROM sales_docs n WHERE n.origin_id = d.id AND n.type = 'NC' AND n.status = 'emitido'), 0)";
    }

    public static function openAmount(Db $db, int $id): int
    {
        return (int) $db->value('SELECT ' . self::openSql() . ' FROM sales_docs d WHERE d.id = ?', [$id]);
    }

    public static function get(Db $db, int $id): array
    {
        $d = $db->one('SELECT d.*, t.name AS party_name, t.nif AS party_nif, t.address AS party_address, t.city AS party_city, t.email AS party_email
                       FROM sales_docs d LEFT JOIN third_parties t ON t.id = d.third_party WHERE d.id = ?', [$id]);
        if (!$d) {
            throw new UserError('Documento não encontrado.');
        }
        $d['type_name'] = self::TYPES[$d['type']]['name'];
        $d['lines'] = $db->all('SELECT * FROM sales_doc_lines WHERE doc_id = ? ORDER BY line_no', [$id]);
        $d['settlements'] = $db->all(
            "SELECT s.invoice_id, s.amount_cents, i.doc_no, i.date, i.total_cents FROM sales_settlements s JOIN sales_docs i ON i.id = s.invoice_id WHERE s.receipt_id = ?",
            [$id]
        );
        $d['origin'] = $d['origin_id'] ? $db->one('SELECT id, type, doc_no, date, status FROM sales_docs WHERE id = ?', [$d['origin_id']]) : null;
        $d['derived'] = $db->all('SELECT id, type, doc_no, date, status, total_cents FROM sales_docs WHERE origin_id = ? ORDER BY id', [$id]);
        $d['receipts'] = $db->all(
            "SELECT r.id, r.doc_no, r.date, r.status, s.amount_cents FROM sales_settlements s JOIN sales_docs r ON r.id = s.receipt_id WHERE s.invoice_id = ? ORDER BY r.date",
            [$id]
        );
        $d['open_cents'] = in_array($d['type'], ['FT', 'ND'], true) && $d['status'] === 'emitido' ? self::openAmount($db, $id) : null;
        $d['vat_summary'] = $db->all(
            'SELECT vat_code, vat_rate_bp, exemption, SUM(net_cents) AS base, SUM(vat_cents) AS vat FROM sales_doc_lines WHERE doc_id = ? GROUP BY vat_code, vat_rate_bp, exemption ORDER BY vat_rate_bp DESC',
            [$id]
        );
        $d['entry'] = $d['entry_id'] ? $db->one('SELECT id, journal, number FROM entries WHERE id = ?', [$d['entry_id']]) : null;
        $d['can_convert'] = $d['status'] === 'emitido' ? (self::CONVERT[$d['type']] ?? []) : [];
        return $d;
    }

    /** Cálculo de uma linha: devolve [net, desconto, iva]. */
    public static function lineAmounts(int $qtyMilli, int $price, int $discountBp, int $rateBp): array
    {
        $gross = intdiv($qtyMilli * $price + 500, 1000);
        $discount = intdiv($gross * $discountBp + 5000, 10000);
        $net = $gross - $discount;
        $vat = intdiv($net * $rateBp + 5000, 10000);
        return [$net, $discount, $vat];
    }

    /**
     * Cria ou altera um rascunho. d: id?, type, date, due_date?, third_party?, customer_name?, customer_nif?, reference?, notes?,
     * origin_id?, payment_method?, delivery_place?, lines: [{item_id?, code?, description, qty, price|price_cents, discount, vat_code, exemption?, account?}],
     * settlements (recibos): [{invoice_id, amount|amount_cents}]. Com issue = true emite logo.
     */
    public static function save(Db $db, array $d): array
    {
        return $db->transaction(static function () use ($db, $d) {
            $id = !empty($d['id']) ? (int) $d['id'] : null;
            if ($id) {
                $cur = $db->one('SELECT type, status, third_party, origin_id FROM sales_docs WHERE id = ?', [$id]);
                if (!$cur) {
                    throw new UserError('Documento não encontrado.');
                }
                if ($cur['status'] !== 'rascunho') {
                    throw new UserError('Um documento emitido não pode ser alterado. Para corrigir uma fatura, emita uma nota de crédito.');
                }
                $type = $cur['type'];
            } else {
                $type = (string) ($d['type'] ?? '');
            }
            $info = self::typeInfo($type);
            $date = (string) ($d['date'] ?? '');
            if (!self::validDate($date)) {
                throw new UserError('Data inválida.');
            }
            $due = (string) ($d['due_date'] ?? '');
            if ($due !== '' && !self::validDate($due)) {
                throw new UserError('Data de vencimento inválida.');
            }
            if ($due !== '' && $due < $date) {
                throw new UserError('A data de vencimento/validade não pode ser anterior à data do documento.');
            }
            if ($due === '' && $info['due'] > 0) {
                $due = date('Y-m-d', strtotime($date . ' +' . $info['due'] . ' days'));
            }
            $party = !empty($d['third_party']) ? (int) $d['third_party'] : null;
            if ($id && !array_key_exists('third_party', $d)) {
                $party = $cur['third_party'] ? (int) $cur['third_party'] : null;
            }
            if ($id && !array_key_exists('origin_id', $d)) {
                $d['origin_id'] = $cur['origin_id'];
            }
            if ($type === 'NC' && !$party && !empty($d['origin_id'])) {
                $party = ((int) $db->value('SELECT third_party FROM sales_docs WHERE id = ?', [(int) $d['origin_id']])) ?: null;
            }
            if ($party && !$db->value('SELECT 1 FROM third_parties WHERE id = ?', [$party])) {
                throw new UserError('Cliente não encontrado.');
            }
            if ($info['party'] && !$party && !($type === 'NC' && !empty($d['origin_id']))) {
                throw new UserError('Escolha o cliente. ' . ($type === 'FT' ? 'Para vendas a consumidor final use uma fatura-recibo.' : ''));
            }
            $method = (string) ($d['payment_method'] ?? '');
            if (in_array($type, ['FR', 'RC'], true)) {
                $method = $method ?: 'NU';
                if (!isset(self::PAYMENT_METHODS[$method])) {
                    throw new UserError('Meio de pagamento inválido.');
                }
            }
            $origin = !empty($d['origin_id']) ? (int) $d['origin_id'] : null;
            if ($type === 'NC') {
                $o = $origin ? $db->one('SELECT * FROM sales_docs WHERE id = ?', [$origin]) : null;
                if (!$o || !in_array($o['type'], ['FT', 'FR', 'ND'], true) || $o['status'] !== 'emitido') {
                    throw new UserError('A nota de crédito tem de indicar a fatura emitida que corrige.');
                }
                $party = $party ?: ($o['third_party'] ? (int) $o['third_party'] : null);
            }

            $lines = [];
            $settlements = [];
            if ($type === 'RC') {
                foreach ((array) ($d['settlements'] ?? []) as $s) {
                    $inv = (int) ($s['invoice_id'] ?? 0);
                    $amount = isset($s['amount_cents']) ? (int) $s['amount_cents'] : Money::toCents((string) ($s['amount'] ?? ''));
                    if ($amount === 0) {
                        continue;
                    }
                    $row = $db->one('SELECT * FROM sales_docs WHERE id = ?', [$inv]);
                    if (!$row || !in_array($row['type'], ['FT', 'ND'], true) || $row['status'] !== 'emitido') {
                        throw new UserError('O recibo só pode liquidar faturas ou notas de débito emitidas.');
                    }
                    if ((int) $row['third_party'] !== $party) {
                        throw new UserError("A fatura {$row['doc_no']} é de outro cliente.");
                    }
                    if ($amount < 0 || $amount > self::openAmount($db, $inv)) {
                        throw new UserError("O valor a receber da fatura {$row['doc_no']} é maior do que o valor em dívida (" . Money::format(self::openAmount($db, $inv)) . ').');
                    }
                    $settlements[] = ['invoice_id' => $inv, 'amount_cents' => $amount];
                }
                if (!$settlements) {
                    throw new UserError('Indique as faturas que o recibo liquida e os valores recebidos.');
                }
                $totals = ['net' => 0, 'discount' => 0, 'vat' => 0, 'total' => array_sum(array_column($settlements, 'amount_cents'))];
            } else {
                [$lines, $totals] = self::normalizeLines($db, (array) ($d['lines'] ?? []), $info['efatura'] !== null || $info['accounting']);
                if (!$lines) {
                    throw new UserError('Acrescente pelo menos uma linha ao documento.');
                }
            }
            if ($type === 'NC') {
                $credited = (int) $db->value("SELECT COALESCE(SUM(total_cents),0) FROM sales_docs WHERE origin_id = ? AND type = 'NC' AND status = 'emitido' AND id IS NOT ?", [$origin, $id]);
                $orig = (int) $db->value('SELECT total_cents FROM sales_docs WHERE id = ?', [$origin]);
                if ($totals['total'] > $orig - $credited) {
                    throw new UserError('O total da nota de crédito (' . Money::format($totals['total']) . ') é maior do que o valor ainda por creditar da fatura (' . Money::format($orig - $credited) . ').');
                }
            }

            $row = [
                'type' => $type, 'date' => $date, 'due_date' => $due ?: null, 'third_party' => $party,
                'customer_name' => $party ? null : (trim((string) ($d['customer_name'] ?? '')) ?: null),
                'customer_nif' => $party ? null : (preg_replace('/\D/', '', (string) ($d['customer_nif'] ?? '')) ?: null),
                'reference' => trim((string) ($d['reference'] ?? '')) ?: null,
                'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
                'origin_id' => $origin, 'payment_method' => $method ?: null,
                'delivery_place' => trim((string) ($d['delivery_place'] ?? '')) ?: null,
                'net_cents' => $totals['net'], 'discount_cents' => $totals['discount'], 'vat_cents' => $totals['vat'], 'total_cents' => $totals['total'],
            ];
            if ($row['customer_nif'] !== null && strlen($row['customer_nif']) !== 9) {
                throw new UserError('O NIF do cliente deve ter 9 dígitos.');
            }
            if ($id) {
                $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($row)));
                $db->run("UPDATE sales_docs SET $sets WHERE id = ?", [...array_values($row), $id]);
                $db->run('DELETE FROM sales_doc_lines WHERE doc_id = ?', [$id]);
                $db->run('DELETE FROM sales_settlements WHERE receipt_id = ?', [$id]);
            } else {
                $id = $db->insert('sales_docs', $row);
            }
            foreach ($lines as $i => $l) {
                $db->insert('sales_doc_lines', ['doc_id' => $id, 'line_no' => $i + 1] + $l);
            }
            foreach ($settlements as $s) {
                $db->insert('sales_settlements', ['receipt_id' => $id] + $s);
            }
            if (!empty($d['issue']) && $d['issue'] !== 'false') {
                return self::issue($db, $id);
            }
            return self::get($db, $id);
        });
    }

    private static function normalizeLines(Db $db, array $in, bool $fiscal): array
    {
        $out = [];
        $t = ['net' => 0, 'discount' => 0, 'vat' => 0, 'total' => 0];
        foreach ($in as $l) {
            $desc = trim((string) ($l['description'] ?? ''));
            $itemId = !empty($l['item_id']) ? (int) $l['item_id'] : null;
            $item = $itemId ? $db->one('SELECT * FROM items WHERE id = ?', [$itemId]) : null;
            if ($itemId && !$item) {
                throw new UserError('Artigo não encontrado.');
            }
            $priceText = (string) ($l['price'] ?? '');
            if ($desc === '' && !$item && trim($priceText) === '' && empty($l['price_cents'])) {
                continue; // linha vazia
            }
            $desc = $desc !== '' ? $desc : (string) ($item['name'] ?? '');
            if ($desc === '') {
                throw new UserError('Há uma linha sem descrição.');
            }
            $qty = isset($l['qty_milli']) ? (int) $l['qty_milli'] : self::toMilli((string) ($l['qty'] ?? '1'));
            if ($qty <= 0) {
                throw new UserError("A quantidade da linha \"{$desc}\" tem de ser maior do que zero.");
            }
            $price = isset($l['price_cents']) ? (int) $l['price_cents'] : ($priceText !== '' ? Money::toCents($priceText) : (int) ($item['price_cents'] ?? 0));
            if ($price < 0) {
                throw new UserError("O preço da linha \"{$desc}\" não pode ser negativo.");
            }
            $discText = str_replace([',', '%', ' '], ['.', '', ''], (string) ($l['discount'] ?? '0'));
            $disc = isset($l['discount_bp']) ? (int) $l['discount_bp'] : (int) round(((float) ($discText ?: 0)) * 100);
            if ($disc < 0 || $disc > 10000) {
                throw new UserError("O desconto da linha \"{$desc}\" tem de estar entre 0 e 100%.");
            }
            $vatCode = (string) (($l['vat_code'] ?? '') ?: ($item['vat_code'] ?? 'NOR'));
            $vat = self::saleVat($db, $vatCode);
            $rate = (int) $vat['rate_bp'];
            $exemption = trim((string) (($l['exemption'] ?? '') ?: ($item['exemption'] ?? '')));
            if ($rate === 0 && $fiscal && $exemption === '') {
                throw new UserError("Indique o motivo de isenção do IVA na linha \"{$desc}\".");
            }
            $account = preg_replace('/\D/', '', (string) (($l['account'] ?? '') ?: ($item['account'] ?? ''))) ?: (($item['kind'] ?? 'P') === 'S' ? '721' : '711');
            self::requireRevenueAccount($db, $account);
            [$net, $discount, $vatAmount] = self::lineAmounts($qty, $price, $disc, $rate);
            $out[] = [
                'item_id' => $itemId, 'code' => ($l['code'] ?? '') !== '' ? (string) $l['code'] : ($item['code'] ?? null),
                'description' => $desc, 'qty_milli' => $qty,
                'unit' => (string) (($l['unit'] ?? '') ?: ($item['unit'] ?? 'UN')),
                'price_cents' => $price, 'discount_bp' => $disc, 'vat_code' => $vatCode, 'vat_rate_bp' => $rate,
                'exemption' => $rate === 0 ? ($exemption ?: null) : null, 'account' => $account,
                'net_cents' => $net, 'vat_cents' => $vatAmount,
            ];
            $t['net'] += $net;
            $t['discount'] += $discount;
            $t['vat'] += $vatAmount;
        }
        $t['total'] = $t['net'] + $t['vat'];
        return [$out, $t];
    }

    /** "1,5" / "2" / "0.250" -> milésimas */
    public static function toMilli(string $s): int
    {
        $s = str_replace([' ', ','], ['', '.'], trim($s));
        if ($s === '' || !is_numeric($s)) {
            throw new UserError("Quantidade inválida: {$s}");
        }
        return (int) round(((float) $s) * 1000);
    }

    private static function validDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    /** Emite um rascunho: número seguinte da série, cadeia de hash e lançamento contabilístico. */
    public static function issue(Db $db, int $id): array
    {
        return $db->transaction(static function () use ($db, $id) {
            $d = self::get($db, $id);
            if ($d['status'] !== 'rascunho') {
                throw new UserError('Este documento já foi emitido.');
            }
            $info = self::TYPES[$d['type']];
            if ($info['efatura'] !== null && $d['date'] > date('Y-m-d')) {
                throw new UserError('Um documento fiscal não pode ter data futura.');
            }
            $series = substr($d['date'], 0, 4);
            $s = $db->one('SELECT * FROM doc_series WHERE type = ? AND series = ?', [$d['type'], $series]);
            if ($s && $s['last_date'] && $d['date'] < $s['last_date']) {
                throw new UserError("Já foi emitido um documento \"{$info['name']}\" com data " . self::dmy($s['last_date'])
                    . '. A numeração tem de seguir a ordem das datas: use essa data ou uma posterior.');
            }
            $number = (int) ($s['last_number'] ?? 0) + 1;
            $docNo = "{$d['type']} {$series}/{$number}";
            $issuedAt = date('Y-m-d H:i:s');
            $prev = (string) ($s['last_hash'] ?? '');
            $hash = hash('sha256', implode(';', [$d['date'], $issuedAt, $docNo, (string) $d['total_cents'], $d['customer_nif'] ?? $d['party_nif'] ?? '', $prev]));
            if ($s) {
                $db->run('UPDATE doc_series SET last_number = ?, last_date = ?, last_hash = ? WHERE type = ? AND series = ?', [$number, $d['date'], $hash, $d['type'], $series]);
            } else {
                $db->insert('doc_series', ['type' => $d['type'], 'series' => $series, 'last_number' => $number, 'last_date' => $d['date'], 'last_hash' => $hash]);
            }
            $db->run(
                "UPDATE sales_docs SET status = 'emitido', series = ?, number = ?, doc_no = ?, issued_at = ?, hash = ?, prev_hash = ?, efatura_status = ?,
                        customer_name = COALESCE(?, customer_name), customer_nif = COALESCE(?, customer_nif), customer_address = ?
                 WHERE id = ?",
                [$series, $number, $docNo, $issuedAt, $hash, $prev ?: null, $info['efatura'] ? 'pendente' : null,
                 $d['party_name'], $d['party_nif'], trim(implode(', ', array_filter([$d['party_address'], $d['party_city']]))) ?: null, $id]
            );
            if ($info['accounting']) {
                $entry = self::post($db, self::get($db, $id));
                $db->run('UPDATE sales_docs SET entry_id = ? WHERE id = ?', [$entry['id'], $id]);
            }
            $db->audit('venda.emitida', ['id' => $id, 'doc' => $docNo, 'total' => $d['total_cents']]);
            return self::get($db, $id);
        });
    }

    private static function dmy(string $iso): string
    {
        return substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4);
    }

    /** Conta de caixa ou banco para o meio de pagamento. */
    public static function cashAccount(Db $db, ?string $method): string
    {
        return $method === 'NU' || $method === null
            ? ($db->meta('sales_cash_account') ?: '111')
            : ($db->meta('sales_bank_account') ?: '121');
    }

    /** Lançamento contabilístico de um documento emitido. */
    private static function post(Db $db, array $d): array
    {
        $party = $d['third_party'] ? (int) $d['third_party'] : null;
        $clientAcc = $party ? ($db->value('SELECT account FROM third_parties WHERE id = ?', [$party]) ?: '211') : '211';
        $who = $d['customer_name'] ?: 'Consumidor final';
        $lines = [];
        if ($d['type'] === 'RC') {
            $lines[] = ['account' => self::cashAccount($db, $d['payment_method']), 'debit_cents' => $d['total_cents'], 'credit_cents' => 0, 'third_party' => $party];
            $lines[] = ['account' => $clientAcc, 'debit_cents' => 0, 'credit_cents' => $d['total_cents'], 'third_party' => $party];
            $journal = $d['payment_method'] === 'NU' ? 'CXA' : 'BCO';
        } else {
            $credit = $d['type'] === 'NC'; // nota de crédito: movimentos ao contrário
            $side = static fn(int $v, bool $isDebit) => ($isDebit xor $credit) ? ['debit_cents' => $v, 'credit_cents' => 0] : ['debit_cents' => 0, 'credit_cents' => $v];
            $debitAcc = $d['type'] === 'FR' ? self::cashAccount($db, $d['payment_method']) : $clientAcc;
            $lines[] = ['account' => $debitAcc, 'third_party' => $party] + $side((int) $d['total_cents'], true);
            // rendimentos por conta (e por código de IVA nas linhas isentas, para o mapa do IVA)
            $rev = [];
            foreach ($d['lines'] as $l) {
                $k = $l['account'] . '|' . ((int) $l['vat_rate_bp'] === 0 ? $l['vat_code'] : '');
                $rev[$k] = ($rev[$k] ?? 0) + (int) $l['net_cents'];
            }
            foreach ($rev as $k => $v) {
                [$acc, $vc] = explode('|', $k);
                if ($v === 0) {
                    continue;
                }
                $lines[] = ['account' => $acc, 'third_party' => $party, 'vat_code' => $vc ?: null, 'vat_base_cents' => $vc ? ($credit ? -$v : $v) : null] + $side($v, false);
            }
            foreach ($d['vat_summary'] as $vs) {
                if ((int) $vs['vat'] === 0) {
                    continue;
                }
                $acc = $db->value('SELECT account FROM vat_codes WHERE code = ?', [$vs['vat_code']]) ?: '2433';
                $lines[] = ['account' => $acc, 'third_party' => $party, 'vat_code' => $vs['vat_code'], 'vat_base_cents' => $credit ? -(int) $vs['base'] : (int) $vs['base']] + $side((int) $vs['vat'], false);
            }
            $journal = 'VEN';
        }
        return Ledger::saveEntry($db, [
            'date' => $d['date'],
            'journal' => $journal,
            'description' => self::TYPES[$d['type']]['name'] . ' ' . $d['doc_no'] . ' — ' . $who,
            'document' => $d['doc_no'],
            'source' => 'vendas',
            'lines' => $lines,
            'validate' => true,
        ]);
    }

    /** Anula um documento emitido (com motivo). Faturas com recibos ou notas de crédito ativas não podem ser anuladas. */
    public static function cancel(Db $db, int $id, string $reason): array
    {
        return $db->transaction(static function () use ($db, $id, $reason) {
            $reason = trim($reason);
            if ($reason === '') {
                throw new UserError('Indique o motivo da anulação.');
            }
            $d = self::get($db, $id);
            if ($d['status'] !== 'emitido') {
                throw new UserError($d['status'] === 'rascunho' ? 'Um rascunho apaga-se, não se anula.' : 'O documento já está anulado.');
            }
            $active = array_filter([...$d['receipts'], ...array_filter($d['derived'], static fn($x) => $x['type'] === 'NC')], static fn($x) => $x['status'] === 'emitido');
            if ($active) {
                throw new UserError('Este documento tem recibos ou notas de crédito emitidos (' . implode(', ', array_column($active, 'doc_no')) . '). Anule-os primeiro.');
            }
            if ($d['entry_id']) {
                Ledger::reverseEntry($db, (int) $d['entry_id']);
            }
            $db->run("UPDATE sales_docs SET status = 'anulado', cancel_reason = ? WHERE id = ?", [$reason, $id]);
            $db->audit('venda.anulada', ['id' => $id, 'doc' => $d['doc_no'], 'motivo' => $reason]);
            return self::get($db, $id);
        });
    }

    public static function deleteDraft(Db $db, int $id): void
    {
        $st = $db->value('SELECT status FROM sales_docs WHERE id = ?', [$id]);
        if ($st !== 'rascunho') {
            throw new UserError('Só é possível apagar rascunhos. Um documento emitido anula-se.');
        }
        $db->run('UPDATE sales_docs SET origin_id = NULL WHERE origin_id = ? AND status = \'rascunho\'', [$id]);
        $db->run('DELETE FROM sales_docs WHERE id = ?', [$id]);
    }

    /** Novo rascunho a partir de outro documento: conversão (orçamento → fatura), nota de crédito ou cópia. */
    public static function derive(Db $db, int $id, string $type, string $mode = 'convert'): array
    {
        $src = self::get($db, $id);
        self::typeInfo($type);
        if ($mode === 'convert' && !in_array($type, self::CONVERT[$src['type']] ?? [], true)) {
            throw new UserError("Não é possível converter {$src['type_name']} em " . self::TYPES[$type]['name'] . '.');
        }
        if ($type === 'NC' && (!in_array($src['type'], ['FT', 'FR', 'ND'], true) || $src['status'] !== 'emitido')) {
            throw new UserError('As notas de crédito fazem-se a partir de uma fatura emitida.');
        }
        if ($src['type'] === 'RC' || $type === 'RC') {
            throw new UserError('Os recibos registam-se com "Registar pagamento" na fatura.');
        }
        $lines = array_map(static fn($l) => [
            'item_id' => $l['item_id'], 'code' => $l['code'], 'description' => $l['description'], 'qty_milli' => (int) $l['qty_milli'],
            'unit' => $l['unit'], 'price_cents' => (int) $l['price_cents'], 'discount_bp' => (int) $l['discount_bp'],
            'vat_code' => $l['vat_code'], 'exemption' => $l['exemption'], 'account' => $l['account'],
        ], $src['lines']);
        return self::save($db, [
            'type' => $type,
            'date' => date('Y-m-d'),
            'third_party' => $src['third_party'],
            'customer_name' => $src['third_party'] ? null : $src['customer_name'],
            'customer_nif' => $src['third_party'] ? null : $src['customer_nif'],
            'reference' => $src['reference'],
            'notes' => $mode === 'copy' ? $src['notes'] : null,
            'origin_id' => $mode === 'copy' ? null : $id,
            'payment_method' => $src['payment_method'],
            'lines' => $lines,
        ]);
    }

    /** Regista o pagamento de uma fatura / nota de débito: emite um recibo. */
    public static function pay(Db $db, int $invoiceId, array $d): array
    {
        $inv = self::get($db, $invoiceId);
        if (!in_array($inv['type'], ['FT', 'ND'], true) || $inv['status'] !== 'emitido') {
            throw new UserError('Só se registam pagamentos de faturas ou notas de débito emitidas.');
        }
        $amount = isset($d['amount_cents']) ? (int) $d['amount_cents'] : (($d['amount'] ?? '') !== '' ? Money::toCents((string) $d['amount']) : (int) $inv['open_cents']);
        if ($amount <= 0) {
            throw new UserError('Indique o valor recebido.');
        }
        return self::save($db, [
            'type' => 'RC',
            'date' => (string) ($d['date'] ?? date('Y-m-d')),
            'third_party' => $inv['third_party'],
            'payment_method' => (string) ($d['payment_method'] ?? 'NU'),
            'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
            'origin_id' => $invoiceId,
            'settlements' => [['invoice_id' => $invoiceId, 'amount_cents' => $amount]],
            'issue' => true,
        ]);
    }

    // ================================================================== dívidas de clientes

    /** Faturas e notas de débito em dívida (emitidas, com valor por receber), com dias de atraso à data $asOf. */
    public static function openDocs(Db $db, ?int $party = null, ?string $asOf = null): array
    {
        $asOf = $asOf ?: date('Y-m-d');
        $open = self::openSql();
        $rows = $db->all(
            "SELECT * FROM (SELECT d.id, d.type, d.doc_no, d.date, d.due_date, d.total_cents, d.third_party,
                    COALESCE(t.name, d.customer_name) AS customer, COALESCE(t.nif, d.customer_nif) AS nif, ($open) AS open_cents
             FROM sales_docs d LEFT JOIN third_parties t ON t.id = d.third_party
             WHERE d.status = 'emitido' AND d.type IN ('FT','ND')" . ($party ? ' AND d.third_party = ?' : '') . ") x
             WHERE open_cents > 0 ORDER BY COALESCE(due_date, date), id",
            $party ? [$party] : []
        );
        foreach ($rows as &$r) {
            $due = $r['due_date'] ?: $r['date'];
            $r['days_overdue'] = max(0, (int) ((strtotime($asOf) - strtotime($due)) / 86400));
            $r['bucket'] = self::bucket($due, $asOf);
        }
        return $rows;
    }

    /** Escalão de antiguidade: a_vencer, d30, d60, d90, mais90 (dias depois do vencimento). */
    private static function bucket(string $due, string $asOf): string
    {
        $days = (int) ((strtotime($asOf) - strtotime($due)) / 86400);
        return match (true) {
            $days <= 0 => 'a_vencer',
            $days <= 30 => 'd30',
            $days <= 60 => 'd60',
            $days <= 90 => 'd90',
            default => 'mais90',
        };
    }

    /** Dívida por cliente com antiguidade. f: q (nome/NIF), overdue (só com valores vencidos), as_of. */
    public static function debtors(Db $db, array $f = []): array
    {
        $asOf = ($f['as_of'] ?? '') ?: date('Y-m-d');
        $by = [];
        $totals = ['open' => 0, 'a_vencer' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'mais90' => 0, 'overdue' => 0];
        foreach (self::openDocs($db, null, $asOf) as $d) {
            $k = $d['third_party'] ?: 'cf';
            $by[$k] ??= ['third_party' => $d['third_party'], 'customer' => $d['customer'] ?: 'Consumidor final', 'nif' => $d['nif'], 'docs' => 0,
                'open' => 0, 'a_vencer' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'mais90' => 0, 'overdue' => 0, 'oldest_due' => null, 'max_days' => 0];
            $r = &$by[$k];
            $v = (int) $d['open_cents'];
            $r['docs']++;
            $r['open'] += $v;
            $r[$d['bucket']] += $v;
            if ($d['bucket'] !== 'a_vencer') {
                $r['overdue'] += $v;
            }
            $r['max_days'] = max($r['max_days'], $d['days_overdue']);
            $due = $d['due_date'] ?: $d['date'];
            $r['oldest_due'] = $r['oldest_due'] === null || $due < $r['oldest_due'] ? $due : $r['oldest_due'];
            unset($r);
        }
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $rows = array_values(array_filter($by, static fn($r) => ($q === '' || str_contains(mb_strtolower($r['customer']), $q) || str_contains((string) $r['nif'], $q))
            && (empty($f['overdue']) || $r['overdue'] > 0)));
        usort($rows, static fn($a, $b) => $b['overdue'] <=> $a['overdue'] ?: $b['open'] <=> $a['open']);
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }
        return ['as_of' => $asOf, 'rows' => $rows, 'totals' => $totals];
    }

    /** Extrato de conta corrente de um cliente (documentos de venda emitidos): saldo inicial, movimentos e saldo acumulado. */
    public static function statement(Db $db, int $party, string $from, string $to): array
    {
        if (!self::validDate($from) || !self::validDate($to) || $from > $to) {
            throw new UserError('Período inválido.');
        }
        $sign = "CASE WHEN type IN ('FT','ND') THEN total_cents WHEN type IN ('NC','RC') THEN -total_cents ELSE 0 END";
        $base = "FROM sales_docs WHERE third_party = ? AND status = 'emitido' AND type IN ('FT','ND','NC','RC')";
        $opening = (int) $db->value("SELECT COALESCE(SUM($sign),0) $base AND date < ?", [$party, $from]);
        $rows = $db->all("SELECT id, type, doc_no, date, due_date, total_cents, ($sign) AS amount $base AND date BETWEEN ? AND ? ORDER BY date, issued_at, id", [$party, $from, $to]);
        $bal = $opening;
        foreach ($rows as &$r) {
            $r['debit'] = max(0, (int) $r['amount']);
            $r['credit'] = max(0, -(int) $r['amount']);
            $bal += (int) $r['amount'];
            $r['balance'] = $bal;
            $r['type_name'] = self::TYPES[$r['type']]['name'];
        }
        unset($r);
        return ['from' => $from, 'to' => $to, 'opening' => $opening, 'closing' => $bal, 'rows' => $rows,
            'debit' => array_sum(array_column($rows, 'debit')), 'credit' => array_sum(array_column($rows, 'credit'))];
    }

    /** Ficha do cliente: dados, totais e faturas em dívida. */
    public static function customer(Db $db, int $party, int $year): array
    {
        $c = $db->one('SELECT * FROM third_parties WHERE id = ?', [$party]);
        if (!$c) {
            throw new UserError('Cliente não encontrado.');
        }
        $open = self::openDocs($db, $party);
        return [
            'customer' => $c,
            'invoiced' => (int) $db->value("SELECT COALESCE(SUM(CASE WHEN type = 'NC' THEN -total_cents ELSE total_cents END),0) FROM sales_docs WHERE third_party = ? AND status = 'emitido' AND type IN ('FT','FR','ND','NC') AND substr(date,1,4) = ?", [$party, (string) $year]),
            'received' => (int) $db->value("SELECT COALESCE(SUM(total_cents),0) FROM sales_docs WHERE third_party = ? AND status = 'emitido' AND type IN ('RC','FR') AND substr(date,1,4) = ?", [$party, (string) $year]),
            'open' => array_sum(array_column($open, 'open_cents')),
            'overdue' => array_sum(array_map(static fn($d) => $d['bucket'] === 'a_vencer' ? 0 : (int) $d['open_cents'], $open)),
            'open_docs' => $open,
            'last_docs' => $db->all("SELECT id, type, doc_no, status, date, total_cents FROM sales_docs WHERE third_party = ? ORDER BY date DESC, id DESC LIMIT 10", [$party]),
        ];
    }

    /** Numeração por tipo e série (para a página da empresa). */
    public static function series(Db $db): array
    {
        $rows = $db->all('SELECT * FROM doc_series ORDER BY series DESC, type');
        foreach ($rows as &$r) {
            $r['name'] = self::TYPES[$r['type']]['name'] ?? $r['type'];
        }
        return $rows;
    }

    /** Verifica a cadeia de hash de todas as séries. */
    public static function verifyChain(Db $db): array
    {
        $errors = [];
        $count = 0;
        foreach ($db->all('SELECT type, series FROM doc_series') as $s) {
            $prev = '';
            $expected = 1;
            foreach ($db->all("SELECT * FROM sales_docs WHERE type = ? AND series = ? AND number IS NOT NULL ORDER BY number", [$s['type'], $s['series']]) as $d) {
                $count++;
                if ((int) $d['number'] !== $expected++) {
                    $errors[] = "Falta o número " . ($expected - 1) . " na série {$s['type']} {$s['series']}.";
                    $expected = (int) $d['number'] + 1;
                }
                $h = hash('sha256', implode(';', [$d['date'], $d['issued_at'], $d['doc_no'], (string) $d['total_cents'], $d['customer_nif'] ?? '', $prev]));
                if ($h !== $d['hash']) {
                    $errors[] = "O documento {$d['doc_no']} foi alterado depois de emitido.";
                }
                $prev = (string) $d['hash'];
            }
        }
        return ['ok' => !$errors, 'documents' => $count, 'errors' => $errors];
    }

    /** Resumo para o painel de vendas. */
    public static function summary(Db $db, int $year): array
    {
        $open = self::openSql();
        return [
            'invoiced' => (int) $db->value("SELECT COALESCE(SUM(CASE WHEN type = 'NC' THEN -total_cents ELSE total_cents END),0) FROM sales_docs WHERE status = 'emitido' AND type IN ('FT','FR','ND','NC') AND substr(date,1,4) = ?", [(string) $year]),
            'open' => (int) $db->value("SELECT COALESCE(SUM($open),0) FROM sales_docs d WHERE d.status = 'emitido' AND d.type IN ('FT','ND')"),
            'overdue' => (int) $db->value("SELECT COALESCE(SUM($open),0) FROM sales_docs d WHERE d.status = 'emitido' AND d.type IN ('FT','ND') AND d.due_date < date('now','localtime')"),
            'drafts' => (int) $db->value("SELECT COUNT(*) FROM sales_docs WHERE status = 'rascunho'"),
            'quotes_open' => (int) $db->value("SELECT COUNT(*) FROM sales_docs d WHERE d.type = 'OR' AND d.status = 'emitido' AND (d.due_date IS NULL OR d.due_date >= date('now','localtime')) AND NOT EXISTS (SELECT 1 FROM sales_docs x WHERE x.origin_id = d.id AND x.status <> 'anulado')"),
        ];
    }
}
