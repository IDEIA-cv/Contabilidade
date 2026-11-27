<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Compras: registo de faturas de fornecedores (FC), despesas pagas no ato (FP), notas de crédito de fornecedores (NF)
 * e pagamentos (PG), com lançamento contabilístico automático, valor em dívida por fatura, dívidas a fornecedores por
 * antiguidade e mapa de pagamentos por data de vencimento.
 */
final class Purchases
{
    public const TYPES = [
        'FC' => ['name' => 'Fatura de fornecedor', 'plural' => 'Faturas de fornecedores', 'party' => true, 'lines' => true, 'due' => 30],
        'FP' => ['name' => 'Despesa paga (fatura-recibo)', 'plural' => 'Despesas pagas', 'party' => false, 'lines' => true, 'due' => 0],
        'NF' => ['name' => 'Nota de crédito de fornecedor', 'plural' => 'Notas de crédito de fornecedores', 'party' => true, 'lines' => true, 'due' => 0],
        'PG' => ['name' => 'Pagamento a fornecedor', 'plural' => 'Pagamentos', 'party' => true, 'lines' => false, 'due' => 0],
    ];

    private static function typeInfo(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new UserError('Tipo de documento de compra desconhecido.');
        }
        return self::TYPES[$type];
    }

    private static function validDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    /** SQL (correlacionado com d) do valor em dívida de uma fatura de fornecedor. */
    private static function openSql(): string
    {
        return "d.total_cents
            - COALESCE((SELECT SUM(s.amount_cents) FROM purchase_settlements s JOIN purchase_docs p ON p.id = s.payment_id
                        WHERE s.invoice_id = d.id AND p.status = 'registado'), 0)
            - COALESCE((SELECT SUM(n.total_cents) FROM purchase_docs n WHERE n.origin_id = d.id AND n.type = 'NF' AND n.status = 'registado'), 0)";
    }

    public static function openAmount(Db $db, int $id): int
    {
        return (int) $db->value('SELECT ' . self::openSql() . ' FROM purchase_docs d WHERE d.id = ?', [$id]);
    }

    /** Conta de gasto sugerida para um fornecedor: a última usada nas suas compras. */
    public static function lastAccount(Db $db, int $party): ?string
    {
        return $db->value(
            "SELECT l.account FROM purchase_doc_lines l JOIN purchase_docs d ON d.id = l.doc_id WHERE d.third_party = ? AND d.status <> 'anulado' ORDER BY d.date DESC, l.id DESC LIMIT 1",
            [$party]
        ) ?: null;
    }

    public static function list(Db $db, array $f = []): array
    {
        $where = ['1=1'];
        $p = [];
        if (($f['type'] ?? '') !== '') {
            self::typeInfo((string) $f['type']);
            $where[] = 'd.type = ?';
            $p[] = $f['type'];
        }
        if (($f['status'] ?? '') !== '') {
            $where[] = 'd.status = ?';
            $p[] = $f['status'];
        }
        if (!empty($f['party'])) {
            $where[] = 'd.third_party = ?';
            $p[] = (int) $f['party'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (($f[$k] ?? '') !== '') {
                $where[] = "d.date $op ?";
                $p[] = $f[$k];
            }
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(d.doc_no LIKE ? OR d.supplier_doc LIKE ? OR t.name LIKE ? OR t.nif LIKE ? OR d.notes LIKE ?)';
            $q = '%' . $f['q'] . '%';
            array_push($p, $q, $q, $q, $q, $q);
        }
        $open = self::openSql();
        if (($f['pay'] ?? '') !== '') {
            $where[] = "d.type = 'FC' AND d.status = 'registado'";
            $where[] = match ($f['pay']) {
                'aberto' => "($open) > 0",
                'vencido' => "($open) > 0 AND d.due_date < date('now','localtime')",
                'pago' => "($open) <= 0",
                default => '1=1',
            };
        }
        $base = 'FROM purchase_docs d LEFT JOIN third_parties t ON t.id = d.third_party WHERE ' . implode(' AND ', $where);
        $limit = max(0, (int) ($f['limit'] ?? 100));
        $offset = max(0, (int) ($f['offset'] ?? 0));
        $sums = $db->one(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN d.status = 'registado' AND d.type IN ('FC','FP') THEN d.total_cents WHEN d.status = 'registado' AND d.type = 'NF' THEN -d.total_cents ELSE 0 END), 0) AS purchased,
                    COALESCE(SUM(CASE WHEN d.status = 'registado' AND d.type = 'FC' THEN ($open) ELSE 0 END), 0) AS open
             $base",
            $p
        );
        $rows = $db->all(
            "SELECT d.id, d.type, d.doc_no, d.supplier_doc, d.status, d.date, d.due_date, d.total_cents, d.vat_cents, d.third_party, d.notes,
                    COALESCE(t.name, 'Sem fornecedor') AS supplier, t.nif,
                    CASE WHEN d.type = 'FC' AND d.status = 'registado' THEN ($open) END AS open_cents
             $base ORDER BY d.date DESC, d.id DESC" . ($limit ? " LIMIT $limit OFFSET $offset" : ''),
            $p
        );
        return ['rows' => $rows, 'total' => (int) $sums['n'], 'purchased' => (int) $sums['purchased'], 'open' => (int) $sums['open'], 'limit' => $limit, 'offset' => $offset];
    }

    public static function get(Db $db, int $id): array
    {
        $d = $db->one('SELECT d.*, t.name AS supplier, t.nif AS supplier_nif FROM purchase_docs d LEFT JOIN third_parties t ON t.id = d.third_party WHERE d.id = ?', [$id]);
        if (!$d) {
            throw new UserError('Documento de compra não encontrado.');
        }
        $d['type_name'] = self::TYPES[$d['type']]['name'];
        $d['lines'] = $db->all('SELECT l.*, a.name AS account_name FROM purchase_doc_lines l LEFT JOIN accounts a ON a.code = l.account WHERE l.doc_id = ? ORDER BY l.line_no', [$id]);
        $d['settlements'] = $db->all('SELECT s.invoice_id, s.amount_cents, i.doc_no, i.supplier_doc, i.date, i.total_cents FROM purchase_settlements s JOIN purchase_docs i ON i.id = s.invoice_id WHERE s.payment_id = ?', [$id]);
        $d['payments'] = $db->all('SELECT p.id, p.doc_no, p.date, p.status, s.amount_cents FROM purchase_settlements s JOIN purchase_docs p ON p.id = s.payment_id WHERE s.invoice_id = ? ORDER BY p.date', [$id]);
        $d['credits'] = $db->all("SELECT id, doc_no, supplier_doc, date, status, total_cents FROM purchase_docs WHERE origin_id = ? AND type = 'NF' ORDER BY id", [$id]);
        $d['origin'] = $d['origin_id'] ? $db->one('SELECT id, type, doc_no, supplier_doc FROM purchase_docs WHERE id = ?', [$d['origin_id']]) : null;
        $d['open_cents'] = $d['type'] === 'FC' && $d['status'] === 'registado' ? self::openAmount($db, $id) : null;
        $d['entry'] = $d['entry_id'] ? $db->one('SELECT id, journal, number FROM entries WHERE id = ?', [$d['entry_id']]) : null;
        $d['attachments'] = $d['entry_id'] ? (int) $db->value('SELECT COUNT(*) FROM entry_attachments WHERE entry_id = ?', [$d['entry_id']]) : 0;
        return $d;
    }

    /** Cria ou altera um rascunho; com register = true regista logo (número interno e lançamento). */
    public static function save(Db $db, array $d): array
    {
        return $db->transaction(static function () use ($db, $d) {
            $id = !empty($d['id']) ? (int) $d['id'] : null;
            if ($id) {
                $cur = $db->one('SELECT type, status, third_party, origin_id FROM purchase_docs WHERE id = ?', [$id]);
                if (!$cur) {
                    throw new UserError('Documento de compra não encontrado.');
                }
                if ($cur['status'] !== 'rascunho') {
                    throw new UserError('Um documento registado não pode ser alterado. Anule-o e registe de novo.');
                }
                $type = $cur['type'];
                $d += ['third_party' => $cur['third_party'], 'origin_id' => $cur['origin_id']];
            } else {
                $type = (string) ($d['type'] ?? '');
            }
            $info = self::typeInfo($type);
            $date = (string) ($d['date'] ?? '');
            if (!self::validDate($date)) {
                throw new UserError('Data inválida.');
            }
            if ($date > date('Y-m-d')) {
                throw new UserError('A data não pode ser futura.');
            }
            $due = (string) ($d['due_date'] ?? '');
            if ($due !== '' && (!self::validDate($due) || $due < $date)) {
                throw new UserError('A data de vencimento é inválida ou anterior à data do documento.');
            }
            if ($due === '' && $info['due'] > 0) {
                $due = date('Y-m-d', strtotime("$date +{$info['due']} days"));
            }
            $party = !empty($d['third_party']) ? (int) $d['third_party'] : null;
            if ($party && !$db->value('SELECT 1 FROM third_parties WHERE id = ?', [$party])) {
                throw new UserError('Fornecedor não encontrado.');
            }
            $origin = !empty($d['origin_id']) ? (int) $d['origin_id'] : null;
            if ($type === 'NF') {
                $o = $origin ? $db->one('SELECT * FROM purchase_docs WHERE id = ?', [$origin]) : null;
                if (!$o || $o['type'] !== 'FC' || $o['status'] !== 'registado') {
                    throw new UserError('A nota de crédito tem de indicar a fatura do fornecedor que corrige.');
                }
                $party = $party ?: ($o['third_party'] ? (int) $o['third_party'] : null);
                if ($date < $o['date']) {
                    throw new UserError("A nota de crédito não pode ter data anterior à da fatura {$o['supplier_doc']} (" . date('d/m/Y', strtotime($o['date'])) . ').');
                }
            }
            if ($info['party'] && !$party) {
                throw new UserError('Escolha o fornecedor.' . ($type === 'FC' ? ' Para pequenas despesas sem fornecedor registado use "Despesa paga".' : ''));
            }
            $supplierDoc = trim((string) ($d['supplier_doc'] ?? ''));
            if ($info['lines'] && $supplierDoc === '') {
                throw new UserError('Indique o número do documento do fornecedor (está impresso na fatura).');
            }
            if ($supplierDoc !== '' && $party && $db->value(
                "SELECT 1 FROM purchase_docs WHERE third_party = ? AND supplier_doc = ? AND type = ? AND status <> 'anulado' AND id IS NOT ?",
                [$party, $supplierDoc, $type, $id]
            )) {
                throw new UserError("O documento {$supplierDoc} deste fornecedor já foi registado.");
            }
            $method = (string) ($d['payment_method'] ?? '');
            if (in_array($type, ['FP', 'PG'], true)) {
                $method = $method ?: 'NU';
                if (!isset(Sales::PAYMENT_METHODS[$method])) {
                    throw new UserError('Meio de pagamento inválido.');
                }
            }

            $lines = [];
            $settlements = [];
            if ($type === 'PG') {
                foreach ((array) ($d['settlements'] ?? []) as $s) {
                    $inv = (int) ($s['invoice_id'] ?? 0);
                    $amount = isset($s['amount_cents']) ? (int) $s['amount_cents'] : Money::toCents((string) ($s['amount'] ?? ''));
                    if ($amount === 0) {
                        continue;
                    }
                    $row = $db->one('SELECT * FROM purchase_docs WHERE id = ?', [$inv]);
                    if (!$row || $row['type'] !== 'FC' || $row['status'] !== 'registado') {
                        throw new UserError('O pagamento só pode liquidar faturas de fornecedor registadas.');
                    }
                    if ((int) $row['third_party'] !== $party) {
                        throw new UserError("A fatura {$row['supplier_doc']} é de outro fornecedor.");
                    }
                    if ($date < $row['date']) {
                        throw new UserError("O pagamento não pode ter data anterior à da fatura {$row['supplier_doc']} (" . date('d/m/Y', strtotime($row['date'])) . ').');
                    }
                    $openAmt = self::openAmount($db, $inv);
                    if ($amount < 0 || $amount > $openAmt) {
                        throw new UserError("O valor a pagar da fatura {$row['supplier_doc']} é maior do que o valor em dívida (" . Money::format($openAmt) . ').');
                    }
                    $settlements[] = ['invoice_id' => $inv, 'amount_cents' => $amount];
                }
                if (!$settlements) {
                    throw new UserError('Indique as faturas que este pagamento liquida.');
                }
                $totals = ['net' => 0, 'vat' => 0, 'total' => array_sum(array_column($settlements, 'amount_cents'))];
            } else {
                [$lines, $totals] = self::normalizeLines($db, (array) ($d['lines'] ?? []), $party);
                if (!$lines) {
                    throw new UserError('Acrescente pelo menos uma linha.');
                }
                if ($type === 'NF') {
                    $credited = (int) $db->value("SELECT COALESCE(SUM(total_cents),0) FROM purchase_docs WHERE origin_id = ? AND type = 'NF' AND status = 'registado' AND id IS NOT ?", [$origin, $id]);
                    $orig = (int) $db->value('SELECT total_cents FROM purchase_docs WHERE id = ?', [$origin]);
                    if ($totals['total'] > $orig - $credited) {
                        throw new UserError('O total da nota de crédito é maior do que o valor ainda por creditar da fatura (' . Money::format($orig - $credited) . ').');
                    }
                }
            }
            $row = [
                'type' => $type, 'date' => $date, 'due_date' => $due ?: null, 'third_party' => $party, 'origin_id' => $origin,
                'supplier_doc' => $supplierDoc ?: null, 'payment_method' => $method ?: null, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
                'net_cents' => $totals['net'], 'vat_cents' => $totals['vat'], 'total_cents' => $totals['total'],
            ];
            if ($id) {
                $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($row)));
                $db->run("UPDATE purchase_docs SET $sets WHERE id = ?", [...array_values($row), $id]);
                $db->run('DELETE FROM purchase_doc_lines WHERE doc_id = ?', [$id]);
                $db->run('DELETE FROM purchase_settlements WHERE payment_id = ?', [$id]);
            } else {
                $id = $db->insert('purchase_docs', $row);
            }
            foreach ($lines as $i => $l) {
                $db->insert('purchase_doc_lines', ['doc_id' => $id, 'line_no' => $i + 1] + $l);
            }
            foreach ($settlements as $s) {
                $db->insert('purchase_settlements', ['payment_id' => $id] + $s);
            }
            if (!empty($d['register']) && $d['register'] !== 'false') {
                return self::register($db, $id);
            }
            return self::get($db, $id);
        });
    }

    private static function normalizeLines(Db $db, array $in, ?int $party): array
    {
        $out = [];
        $t = ['net' => 0, 'vat' => 0, 'total' => 0];
        $fallback = $party ? self::lastAccount($db, $party) : null;
        foreach ($in as $l) {
            $item = !empty($l['item_id']) ? $db->one('SELECT * FROM items WHERE id = ?', [(int) $l['item_id']]) : null;
            $desc = trim((string) ($l['description'] ?? '')) ?: (string) ($item['name'] ?? '');
            $priceText = trim((string) ($l['price'] ?? ''));
            if ($desc === '' && $priceText === '' && empty($l['price_cents'])) {
                continue;
            }
            if ($desc === '') {
                throw new UserError('Há uma linha sem descrição.');
            }
            $qty = isset($l['qty_milli']) ? (int) $l['qty_milli'] : Sales::toMilli((string) (($l['qty'] ?? '') ?: '1'));
            if ($qty <= 0) {
                throw new UserError("A quantidade da linha \"{$desc}\" tem de ser maior do que zero.");
            }
            $price = isset($l['price_cents']) ? (int) $l['price_cents'] : Money::toCents($priceText);
            if ($price < 0) {
                throw new UserError("O valor da linha \"{$desc}\" não pode ser negativo.");
            }
            $discText = str_replace([',', '%', ' '], ['.', '', ''], (string) ($l['discount'] ?? '0'));
            $disc = isset($l['discount_bp']) ? (int) $l['discount_bp'] : (int) round(((float) ($discText ?: 0)) * 100);
            if ($disc < 0 || $disc > 10000) {
                throw new UserError("O desconto da linha \"{$desc}\" tem de estar entre 0 e 100%.");
            }
            $vatCode = (string) (($l['vat_code'] ?? '') ?: 'DED');
            $vat = $db->one('SELECT * FROM vat_codes WHERE code = ?', [$vatCode]);
            if (!$vat || !in_array($vat['kind'], ['dedutivel', 'isento'], true)) {
                throw new UserError('Escolha uma taxa de IVA de compras (dedutível ou isento).');
            }
            $account = preg_replace('/\D/', '', (string) ($l['account'] ?? '')) ?: ($fallback ?: (($item['kind'] ?? 'S') === 'P' ? '311' : '6268'));
            if (!$db->value('SELECT 1 FROM accounts WHERE code = ?', [$account])) {
                throw new UserError("A conta {$account} não existe no plano de contas.");
            }
            if (!Ledger::isMovementAccount($db, $account)) {
                throw new UserError("A conta {$account} tem subcontas. Escolha uma conta de movimento.");
            }
            if (!preg_match('/^(3|4|6)/', $account)) {
                throw new UserError("A conta {$account} não é de compras, investimento ou gastos (classes 3, 4 ou 6).");
            }
            [$net, , $vatAmount] = Sales::lineAmounts($qty, $price, $disc, (int) $vat['rate_bp']);
            $out[] = ['item_id' => $item['id'] ?? null, 'description' => $desc, 'qty_milli' => $qty, 'price_cents' => $price, 'discount_bp' => $disc,
                'vat_code' => $vatCode, 'vat_rate_bp' => (int) $vat['rate_bp'], 'account' => $account, 'net_cents' => $net, 'vat_cents' => $vatAmount];
            $t['net'] += $net;
            $t['vat'] += $vatAmount;
        }
        $t['total'] = $t['net'] + $t['vat'];
        return [$out, $t];
    }

    /** Regista: número interno da série (tipo/ano) e lançamento contabilístico. */
    public static function register(Db $db, int $id): array
    {
        return $db->transaction(static function () use ($db, $id) {
            $d = self::get($db, $id);
            if ($d['status'] !== 'rascunho') {
                throw new UserError('Este documento já foi registado.');
            }
            $series = substr($d['date'], 0, 4);
            $number = (int) $db->value('SELECT COALESCE(MAX(number), 0) FROM purchase_docs WHERE type = ? AND series = ?', [$d['type'], $series]) + 1;
            $docNo = "{$d['type']} {$series}/{$number}";
            $db->run("UPDATE purchase_docs SET status = 'registado', series = ?, number = ?, doc_no = ?, registered_at = ? WHERE id = ?",
                [$series, $number, $docNo, date('Y-m-d H:i:s'), $id]);
            $entry = self::post($db, self::get($db, $id));
            $db->run('UPDATE purchase_docs SET entry_id = ? WHERE id = ?', [$entry['id'], $id]);
            $db->audit('compra.registada', ['id' => $id, 'doc' => $docNo, 'fornecedor' => $d['supplier_doc'], 'total' => $d['total_cents']]);
            return self::get($db, $id);
        });
    }

    private static function post(Db $db, array $d): array
    {
        $party = $d['third_party'] ? (int) $d['third_party'] : null;
        $supAcc = $party ? ($db->value('SELECT account FROM third_parties WHERE id = ?', [$party]) ?: '221') : '221';
        if (!preg_match('/^2/', $supAcc)) {
            $supAcc = '221';
        }
        $who = $d['supplier'] ?: 'sem fornecedor';
        $lines = [];
        if ($d['type'] === 'PG') {
            $lines[] = ['account' => $supAcc, 'debit_cents' => $d['total_cents'], 'credit_cents' => 0, 'third_party' => $party];
            $lines[] = ['account' => Sales::cashAccount($db, $d['payment_method']), 'debit_cents' => 0, 'credit_cents' => $d['total_cents']];
            $journal = $d['payment_method'] === 'NU' ? 'CXA' : 'BCO';
            $desc = 'Pagamento ' . $d['doc_no'] . ' — ' . $who;
        } else {
            $credit = $d['type'] === 'NF';
            $side = static fn(int $v, bool $isDebit) => ($isDebit xor $credit) ? ['debit_cents' => $v, 'credit_cents' => 0] : ['debit_cents' => 0, 'credit_cents' => $v];
            $by = [];
            foreach ($d['lines'] as $l) {
                $k = $l['account'] . '|' . ((int) $l['vat_rate_bp'] === 0 ? $l['vat_code'] : '');
                $by[$k] = ($by[$k] ?? 0) + (int) $l['net_cents'];
            }
            foreach ($by as $k => $v) {
                [$acc, $vc] = explode('|', $k);
                if ($v !== 0) {
                    $lines[] = ['account' => $acc, 'third_party' => $party, 'vat_code' => $vc ?: null, 'vat_base_cents' => $vc ? ($credit ? -$v : $v) : null] + $side($v, true);
                }
            }
            $vat = [];
            foreach ($d['lines'] as $l) {
                if ((int) $l['vat_cents'] !== 0) {
                    $vat[$l['vat_code']] ??= ['base' => 0, 'vat' => 0];
                    $vat[$l['vat_code']]['base'] += (int) $l['net_cents'];
                    $vat[$l['vat_code']]['vat'] += (int) $l['vat_cents'];
                }
            }
            foreach ($vat as $code => $v) {
                $acc = $db->value('SELECT account FROM vat_codes WHERE code = ?', [$code]) ?: '2432';
                $lines[] = ['account' => $acc, 'third_party' => $party, 'vat_code' => $code, 'vat_base_cents' => $credit ? -$v['base'] : $v['base']] + $side($v['vat'], true);
            }
            $creditAcc = $d['type'] === 'FP' ? Sales::cashAccount($db, $d['payment_method']) : $supAcc;
            $lines[] = ['account' => $creditAcc, 'third_party' => $party] + $side((int) $d['total_cents'], false);
            $journal = 'COM';
            $desc = self::TYPES[$d['type']]['name'] . ' ' . $d['supplier_doc'] . ' — ' . $who;
        }
        return Ledger::saveEntry($db, ['date' => $d['date'], 'journal' => $journal, 'description' => $desc,
            'document' => $d['supplier_doc'] ?: $d['doc_no'], 'source' => 'compras', 'lines' => $lines, 'validate' => true]);
    }

    public static function cancel(Db $db, int $id, string $reason): array
    {
        return $db->transaction(static function () use ($db, $id, $reason) {
            if (trim($reason) === '') {
                throw new UserError('Indique o motivo da anulação.');
            }
            $d = self::get($db, $id);
            if ($d['status'] !== 'registado') {
                throw new UserError($d['status'] === 'rascunho' ? 'Um rascunho apaga-se, não se anula.' : 'O documento já está anulado.');
            }
            $active = array_filter([...$d['payments'], ...$d['credits']], static fn($x) => $x['status'] === 'registado');
            if ($active) {
                throw new UserError('Esta fatura tem pagamentos ou notas de crédito registados (' . implode(', ', array_column($active, 'doc_no')) . '). Anule-os primeiro.');
            }
            if ($d['entry_id']) {
                Ledger::reverseEntry($db, (int) $d['entry_id']);
            }
            $db->run("UPDATE purchase_docs SET status = 'anulado', cancel_reason = ? WHERE id = ?", [trim($reason), $id]);
            $db->audit('compra.anulada', ['id' => $id, 'doc' => $d['doc_no'], 'motivo' => trim($reason)]);
            return self::get($db, $id);
        });
    }

    /** Cria um rascunho de nota de crédito a partir de uma fatura de fornecedor registada, com as mesmas linhas. */
    public static function creditFrom(Db $db, int $id): array
    {
        $o = self::get($db, $id);
        if ($o['type'] !== 'FC' || $o['status'] !== 'registado') {
            throw new UserError('Só é possível registar notas de crédito de faturas de fornecedor registadas.');
        }
        $lines = array_map(static fn($l) => ['item_id' => $l['item_id'], 'description' => $l['description'], 'qty_milli' => (int) $l['qty_milli'],
            'price_cents' => (int) $l['price_cents'], 'discount_bp' => (int) $l['discount_bp'], 'vat_code' => $l['vat_code'], 'account' => $l['account']], $o['lines']);
        $db->transaction(static function () use ($db, $o, $lines, &$new) {
            $id = $db->insert('purchase_docs', ['type' => 'NF', 'date' => date('Y-m-d'), 'third_party' => $o['third_party'], 'origin_id' => $o['id'],
                'supplier_doc' => null, 'net_cents' => $o['net_cents'], 'vat_cents' => $o['vat_cents'], 'total_cents' => $o['total_cents']]);
            [$norm] = self::normalizeLines($db, $lines, $o['third_party'] ? (int) $o['third_party'] : null);
            foreach ($norm as $i => $l) {
                $db->insert('purchase_doc_lines', ['doc_id' => $id, 'line_no' => $i + 1] + $l);
            }
            $new = $id;
        });
        return self::get($db, $new);
    }

    public static function deleteDraft(Db $db, int $id): void
    {
        if ($db->value('SELECT status FROM purchase_docs WHERE id = ?', [$id]) !== 'rascunho') {
            throw new UserError('Só é possível apagar rascunhos.');
        }
        $db->run('DELETE FROM purchase_docs WHERE id = ?', [$id]);
    }

    /** Regista o pagamento (total ou parcial) de uma ou várias faturas do mesmo fornecedor. */
    public static function pay(Db $db, array $d): array
    {
        $ids = array_map('intval', (array) ($d['invoices'] ?? (isset($d['id']) ? [$d['id']] : [])));
        if (!$ids) {
            throw new UserError('Escolha as faturas a pagar.');
        }
        $first = self::get($db, $ids[0]);
        $settlements = [];
        if (count($ids) === 1 && ($d['amount'] ?? '') !== '') {
            $settlements[] = ['invoice_id' => $ids[0], 'amount_cents' => Money::toCents((string) $d['amount'])];
        } else {
            foreach ($ids as $i) {
                $settlements[] = ['invoice_id' => $i, 'amount_cents' => self::openAmount($db, $i)];
            }
        }
        return self::save($db, ['type' => 'PG', 'date' => (string) (($d['date'] ?? '') ?: date('Y-m-d')), 'third_party' => $first['third_party'],
            'payment_method' => (string) ($d['payment_method'] ?? 'TB'), 'notes' => $d['notes'] ?? null, 'settlements' => $settlements, 'register' => true]);
    }

    /** Faturas em dívida, com dias de atraso e escalão de antiguidade. */
    public static function openDocs(Db $db, ?int $party = null, ?string $asOf = null): array
    {
        $asOf = $asOf ?: date('Y-m-d');
        $open = self::openSql();
        $rows = $db->all(
            "SELECT * FROM (SELECT d.id, d.doc_no, d.supplier_doc, d.date, d.due_date, d.total_cents, d.third_party, t.name AS supplier, t.nif, ($open) AS open_cents
             FROM purchase_docs d LEFT JOIN third_parties t ON t.id = d.third_party
             WHERE d.status = 'registado' AND d.type = 'FC'" . ($party ? ' AND d.third_party = ?' : '') . ") x WHERE open_cents > 0 ORDER BY COALESCE(due_date, date), id",
            $party ? [$party] : []
        );
        foreach ($rows as &$r) {
            $days = (int) ((strtotime($asOf) - strtotime($r['due_date'] ?: $r['date'])) / 86400);
            $r['days_overdue'] = max(0, $days);
            $r['days_to_due'] = max(0, -$days);
            $r['bucket'] = match (true) { $days <= 0 => 'a_vencer', $days <= 30 => 'd30', $days <= 60 => 'd60', $days <= 90 => 'd90', default => 'mais90' };
        }
        return $rows;
    }

    /** Dívidas a fornecedores por antiguidade. */
    public static function creditors(Db $db, array $f = []): array
    {
        $asOf = ($f['as_of'] ?? '') ?: date('Y-m-d');
        $by = [];
        $totals = ['open' => 0, 'a_vencer' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'mais90' => 0, 'overdue' => 0];
        foreach (self::openDocs($db, null, $asOf) as $d) {
            $k = (int) $d['third_party'];
            $by[$k] ??= ['third_party' => $k, 'supplier' => $d['supplier'], 'nif' => $d['nif'], 'docs' => 0, 'open' => 0, 'a_vencer' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'mais90' => 0, 'overdue' => 0, 'next_due' => null];
            $v = (int) $d['open_cents'];
            $by[$k]['docs']++;
            $by[$k]['open'] += $v;
            $by[$k][$d['bucket']] += $v;
            if ($d['bucket'] !== 'a_vencer') {
                $by[$k]['overdue'] += $v;
            }
            $due = $d['due_date'] ?: $d['date'];
            $by[$k]['next_due'] = $by[$k]['next_due'] === null || $due < $by[$k]['next_due'] ? $due : $by[$k]['next_due'];
        }
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $rows = array_values(array_filter($by, static fn($r) => ($q === '' || str_contains(mb_strtolower((string) $r['supplier']), $q) || str_contains((string) $r['nif'], $q))
            && (empty($f['overdue']) || $r['overdue'] > 0)));
        usort($rows, static fn($a, $b) => $b['overdue'] <=> $a['overdue'] ?: $b['open'] <=> $a['open']);
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }
        return ['as_of' => $asOf, 'rows' => $rows, 'totals' => $totals];
    }

    /** Mapa de pagamentos: faturas em dívida agrupadas por prazo (vencidas, esta semana, próximos 30 dias, depois). */
    public static function schedule(Db $db, ?string $asOf = null): array
    {
        $asOf = $asOf ?: date('Y-m-d');
        $weekEnd = date('Y-m-d', strtotime($asOf . ' +' . (7 - (int) date('N', strtotime($asOf))) . ' days'));
        $in30 = date('Y-m-d', strtotime("$asOf +30 days"));
        $groups = ['vencidas' => [], 'semana' => [], 'mes' => [], 'depois' => []];
        foreach (self::openDocs($db, null, $asOf) as $d) {
            $due = $d['due_date'] ?: $d['date'];
            $g = $due < $asOf ? 'vencidas' : ($due <= $weekEnd ? 'semana' : ($due <= $in30 ? 'mes' : 'depois'));
            $groups[$g][] = $d;
        }
        $sum = static fn(array $rows) => array_sum(array_map(static fn($r) => (int) $r['open_cents'], $rows));
        return ['as_of' => $asOf, 'week_end' => $weekEnd, 'groups' => $groups, 'totals' => array_map($sum, $groups)];
    }

    /** Ficha do fornecedor: faturas por pagar e totais do ano. */
    public static function supplier(Db $db, int $party, int $year): array
    {
        $open = self::openDocs($db, $party);
        return [
            'purchased' => (int) $db->value("SELECT COALESCE(SUM(CASE WHEN type = 'NF' THEN -total_cents ELSE total_cents END),0) FROM purchase_docs WHERE third_party = ? AND status = 'registado' AND type IN ('FC','FP','NF') AND substr(date,1,4) = ?", [$party, (string) $year]),
            'paid' => (int) $db->value("SELECT COALESCE(SUM(total_cents),0) FROM purchase_docs WHERE third_party = ? AND status = 'registado' AND type IN ('PG','FP') AND substr(date,1,4) = ?", [$party, (string) $year]),
            'open' => array_sum(array_column($open, 'open_cents')),
            'overdue' => array_sum(array_map(static fn($d) => $d['bucket'] === 'a_vencer' ? 0 : (int) $d['open_cents'], $open)),
            'open_docs' => $open,
            'last_docs' => $db->all('SELECT id, type, doc_no, supplier_doc, status, date, total_cents FROM purchase_docs WHERE third_party = ? ORDER BY date DESC, id DESC LIMIT 10', [$party]),
            'last_account' => self::lastAccount($db, $party),
        ];
    }

    public static function summary(Db $db, int $year): array
    {
        $open = self::openSql();
        return [
            'purchased' => (int) $db->value("SELECT COALESCE(SUM(CASE WHEN type = 'NF' THEN -total_cents ELSE total_cents END),0) FROM purchase_docs WHERE status = 'registado' AND type IN ('FC','FP','NF') AND substr(date,1,4) = ?", [(string) $year]),
            'open' => (int) $db->value("SELECT COALESCE(SUM($open),0) FROM purchase_docs d WHERE d.status = 'registado' AND d.type = 'FC'"),
            'overdue' => (int) $db->value("SELECT COALESCE(SUM($open),0) FROM purchase_docs d WHERE d.status = 'registado' AND d.type = 'FC' AND d.due_date < date('now','localtime')"),
            'week' => (int) $db->value("SELECT COALESCE(SUM($open),0) FROM purchase_docs d WHERE d.status = 'registado' AND d.type = 'FC' AND d.due_date BETWEEN date('now','localtime') AND date('now','localtime','+7 days')"),
            'drafts' => (int) $db->value("SELECT COUNT(*) FROM purchase_docs WHERE status = 'rascunho'"),
        ];
    }
}
