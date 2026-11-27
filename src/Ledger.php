<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Núcleo contabilístico: plano de contas, terceiros, diários, exercícios e lançamentos.
 *
 * Regras principais:
 *  - Só se lança em contas de movimento (contas sem subcontas) e ativas.
 *  - Um lançamento validado é imutável; corrige-se com um estorno.
 *  - Cada lançamento validado recebe número sequencial por diário/exercício e entra numa cadeia de hash.
 */
final class Ledger
{
    // ---------------------------------------------------------------- plano de contas

    public static function seedChart(Db $db, array $chart): void
    {
        usort($chart, static fn($a, $b) => strlen($a[0]) <=> strlen($b[0]) ?: strcmp($a[0], $b[0]));
        foreach ($chart as [$code, $name]) {
            self::insertAccount($db, (string) $code, (string) $name);
        }
    }

    public static function parentOf(Db $db, string $code): ?string
    {
        for ($i = strlen($code) - 1; $i >= 1; $i--) {
            $p = substr($code, 0, $i);
            if ($db->value('SELECT 1 FROM accounts WHERE code = ?', [$p])) {
                return $p;
            }
        }
        return null;
    }

    private static function insertAccount(Db $db, string $code, string $name): void
    {
        $db->insert('accounts', [
            'code' => $code,
            'name' => $name,
            'parent' => self::parentOf($db, $code),
            'class' => (int) $code[0],
            'level' => strlen($code),
        ]);
    }

    public static function accounts(Db $db): array
    {
        return $db->all(
            'SELECT a.code, a.name, a.parent, a.class, a.level, a.active,
                    NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent = a.code) AS movement,
                    EXISTS (SELECT 1 FROM entry_lines l WHERE l.account = a.code) AS used
             FROM accounts a ORDER BY a.code'
        );
    }

    public static function saveAccount(Db $db, array $data): array
    {
        $code = preg_replace('/\D/', '', (string) ($data['code'] ?? '')) ?? '';
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $code[0] < '1' || $code[0] > '8') {
            throw new UserError('O código da conta deve começar por um dígito de 1 a 8.');
        }
        if ($name === '') {
            throw new UserError('Indique o nome da conta.');
        }
        return $db->transaction(static function () use ($db, $code, $name, $data) {
            $exists = $db->one('SELECT * FROM accounts WHERE code = ?', [$code]);
            if ($exists) {
                $db->run('UPDATE accounts SET name = ?, active = ? WHERE code = ?', [$name, (int) ($data['active'] ?? 1), $code]);
                $db->audit('conta.alterada', ['code' => $code]);
            } else {
                $parent = self::parentOf($db, $code);
                if ($parent !== null && $db->value('SELECT 1 FROM entry_lines WHERE account = ?', [$parent])) {
                    throw new UserError("A conta {$parent} já tem movimentos; não pode receber subcontas. Crie a subconta antes de lançar.");
                }
                self::insertAccount($db, $code, $name);
                // contas criadas depois podem ser mães de contas já existentes (ex.: criar 2111 depois de 21111)
                $db->run('UPDATE accounts SET parent = ? WHERE code LIKE ? AND length(code) > ? AND (parent IS NULL OR length(parent) < ?)',
                    [$code, $code . '%', strlen($code), strlen($code)]);
                $db->audit('conta.criada', ['code' => $code]);
            }
            return $db->one('SELECT * FROM accounts WHERE code = ?', [$code]);
        });
    }

    public static function isMovementAccount(Db $db, string $code): bool
    {
        return (bool) $db->value(
            'SELECT 1 FROM accounts a WHERE a.code = ? AND a.active = 1 AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent = a.code)',
            [$code]
        );
    }

    // ---------------------------------------------------------------- dados base

    public static function seedDefaults(Db $db): void
    {
        $journals = [
            ['ABE', 'Abertura', 'abertura'],
            ['DIV', 'Operações diversas', 'normal'],
            ['VEN', 'Vendas', 'normal'],
            ['COM', 'Compras', 'normal'],
            ['CXA', 'Caixa', 'normal'],
            ['BCO', 'Bancos', 'normal'],
            ['SAL', 'Salários', 'normal'],
            ['APU', 'Apuramento de resultados', 'apuramento'],
        ];
        foreach ($journals as [$c, $n, $k]) {
            $db->insert('journals', ['code' => $c, 'name' => $n, 'kind' => $k]);
        }
        // Taxa normal de IVA em Cabo Verde: 15%. Outras taxas podem ser acrescentadas na aplicação.
        $vat = [
            ['NOR', 'IVA liquidado — taxa normal 15%', 1500, 'liquidado', '2433'],
            ['DED', 'IVA dedutível — taxa normal 15%', 1500, 'dedutivel', '2432'],
            ['ISE', 'Isento / não sujeito', 0, 'isento', null],
        ];
        foreach ($vat as [$c, $n, $r, $k, $a]) {
            $db->insert('vat_codes', ['code' => $c, 'name' => $n, 'rate_bp' => $r, 'kind' => $k, 'account' => $a]);
        }
    }

    public static function createFiscalYear(Db $db, int $year): array
    {
        if ($db->value('SELECT 1 FROM fiscal_years WHERE year = ?', [$year])) {
            throw new UserError("O exercício {$year} já existe.");
        }
        $db->insert('fiscal_years', ['year' => $year, 'start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"]);
        $db->audit('exercicio.criado', ['year' => $year]);
        return $db->one('SELECT * FROM fiscal_years WHERE year = ?', [$year]);
    }

    public static function fiscalYears(Db $db): array
    {
        return $db->all('SELECT f.*, (SELECT COUNT(*) FROM entries e WHERE e.fiscal_year = f.year) AS entries FROM fiscal_years f ORDER BY year');
    }

    public static function lockPeriod(Db $db, int $year, ?string $until): void
    {
        $fy = $db->one('SELECT * FROM fiscal_years WHERE year = ?', [$year]);
        if (!$fy) {
            throw new UserError('Exercício inexistente.');
        }
        if ($until !== null && ($until < $fy['start_date'] || $until > $fy['end_date'])) {
            throw new UserError('A data de bloqueio tem de estar dentro do exercício.');
        }
        $db->run('UPDATE fiscal_years SET locked_until = ? WHERE year = ?', [$until, $year]);
        $db->audit('periodo.bloqueado', ['year' => $year, 'until' => $until]);
    }

    public static function journals(Db $db): array
    {
        return $db->all('SELECT * FROM journals ORDER BY code');
    }

    public static function vatCodes(Db $db): array
    {
        return $db->all('SELECT * FROM vat_codes ORDER BY code');
    }

    // ---------------------------------------------------------------- terceiros

    public static function thirdParties(Db $db, string $search = ''): array
    {
        if ($search !== '') {
            $like = '%' . $search . '%';
            return $db->all('SELECT * FROM third_parties WHERE name LIKE ? OR nif LIKE ? ORDER BY name LIMIT 200', [$like, $like]);
        }
        return $db->all('SELECT * FROM third_parties ORDER BY name');
    }

    public static function saveThirdParty(Db $db, array $d): array
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new UserError('Indique o nome do terceiro.');
        }
        $nif = preg_replace('/\D/', '', (string) ($d['nif'] ?? '')) ?? '';
        $country = strtoupper(trim((string) ($d['country'] ?? 'CV'))) ?: 'CV';
        if ($nif !== '' && $country === 'CV' && strlen($nif) !== 9) {
            throw new UserError('O NIF cabo-verdiano deve ter 9 dígitos.');
        }
        $kind = in_array($d['kind'] ?? 'C', ['C', 'F', 'A', 'O'], true) ? $d['kind'] : 'C';
        $row = [
            'nif' => $nif ?: null,
            'name' => $name,
            'kind' => $kind,
            'address' => $d['address'] ?? null,
            'city' => $d['city'] ?? null,
            'country' => $country,
            'email' => $d['email'] ?? null,
            'phone' => $d['phone'] ?? null,
            'account' => ($d['account'] ?? '') !== '' ? $d['account'] : null,
        ];
        if (!empty($d['id'])) {
            $sets = implode(',', array_map(static fn($k) => "$k = ?", array_keys($row)));
            $db->run("UPDATE third_parties SET $sets WHERE id = ?", [...array_values($row), (int) $d['id']]);
            $id = (int) $d['id'];
        } else {
            if ($nif !== '' && $db->value('SELECT 1 FROM third_parties WHERE nif = ? AND kind = ?', [$nif, $kind])) {
                throw new UserError('Já existe um terceiro deste tipo com este NIF.');
            }
            $id = $db->insert('third_parties', $row);
        }
        return $db->one('SELECT * FROM third_parties WHERE id = ?', [$id]);
    }

    // ---------------------------------------------------------------- lançamentos

    public static function listEntries(Db $db, array $f): array
    {
        $where = ['1=1'];
        $p = [];
        if (!empty($f['year'])) { $where[] = 'e.fiscal_year = ?'; $p[] = (int) $f['year']; }
        if (!empty($f['journal'])) { $where[] = 'e.journal = ?'; $p[] = $f['journal']; }
        if (!empty($f['status'])) { $where[] = 'e.status = ?'; $p[] = $f['status']; }
        if (!empty($f['from'])) { $where[] = 'e.date >= ?'; $p[] = $f['from']; }
        if (!empty($f['to'])) { $where[] = 'e.date <= ?'; $p[] = $f['to']; }
        if (!empty($f['account'])) {
            $where[] = 'EXISTS (SELECT 1 FROM entry_lines l WHERE l.entry_id = e.id AND l.account LIKE ?)';
            $p[] = $f['account'] . '%';
        }
        if (!empty($f['q'])) {
            $where[] = '(e.description LIKE ? OR e.document LIKE ?)';
            $p[] = '%' . $f['q'] . '%';
            $p[] = '%' . $f['q'] . '%';
        }
        $limit = min(1000, max(1, (int) ($f['limit'] ?? 200)));
        $offset = max(0, (int) ($f['offset'] ?? 0));
        $sql = 'SELECT e.id, e.fiscal_year, e.journal, e.number, e.date, e.description, e.document, e.status, e.source,
                       e.total_cents, e.reverses, e.reversed_by,
                       (SELECT COUNT(*) FROM entry_attachments a WHERE a.entry_id = e.id) AS attachments
                FROM entries e WHERE ' . implode(' AND ', $where) .
               " ORDER BY e.date DESC, e.id DESC LIMIT $limit OFFSET $offset";
        $rows = $db->all($sql, $p);
        $total = (int) $db->value('SELECT COUNT(*) FROM entries e WHERE ' . implode(' AND ', $where), $p);
        return ['rows' => $rows, 'total' => $total];
    }

    public static function getEntry(Db $db, int $id): array
    {
        $e = $db->one('SELECT * FROM entries WHERE id = ?', [$id]);
        if (!$e) {
            throw new UserError('Lançamento não encontrado.');
        }
        $e['lines'] = $db->all(
            'SELECT l.*, a.name AS account_name, t.name AS third_party_name, t.nif AS third_party_nif
             FROM entry_lines l JOIN accounts a ON a.code = l.account LEFT JOIN third_parties t ON t.id = l.third_party
             WHERE l.entry_id = ? ORDER BY l.line_no',
            [$id]
        );
        return $e;
    }

    /**
     * Cria ou altera um lançamento em rascunho. Se $data['validate'] for verdadeiro, valida-o logo.
     * Linhas: [{account, debit, credit, description?, third_party?, vat_code?, vat_base?}] com valores em texto ou centavos (*_cents).
     */
    public static function saveEntry(Db $db, array $data): array
    {
        return $db->transaction(static function () use ($db, $data) {
            $date = (string) ($data['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw new UserError('Data inválida.');
            }
            $year = (int) substr($date, 0, 4);
            if (!$db->value('SELECT 1 FROM fiscal_years WHERE year = ?', [$year])) {
                throw new UserError("Não existe o exercício {$year}. Crie-o primeiro.");
            }
            $journal = (string) ($data['journal'] ?? 'DIV');
            if (!$db->value('SELECT 1 FROM journals WHERE code = ?', [$journal])) {
                throw new UserError('Diário inexistente.');
            }
            $description = trim((string) ($data['description'] ?? ''));
            if ($description === '') {
                throw new UserError('Indique a descrição do lançamento.');
            }
            $lines = self::normalizeLines($db, $data['lines'] ?? []);
            $total = array_sum(array_column($lines, 'debit_cents'));

            if (!empty($data['id'])) {
                $id = (int) $data['id'];
                $cur = $db->one('SELECT status FROM entries WHERE id = ?', [$id]);
                if (!$cur) {
                    throw new UserError('Lançamento não encontrado.');
                }
                if ($cur['status'] !== 'rascunho') {
                    throw new UserError('Um lançamento validado não pode ser alterado. Use o estorno.');
                }
                $db->run('UPDATE entries SET fiscal_year=?, journal=?, date=?, description=?, document=?, total_cents=? WHERE id=?',
                    [$year, $journal, $date, $description, $data['document'] ?? null, $total, $id]);
                $db->run('DELETE FROM entry_lines WHERE entry_id = ?', [$id]);
            } else {
                $id = $db->insert('entries', [
                    'fiscal_year' => $year,
                    'journal' => $journal,
                    'date' => $date,
                    'description' => $description,
                    'document' => $data['document'] ?? null,
                    'source' => $data['source'] ?? 'manual',
                    'total_cents' => $total,
                ]);
            }
            foreach ($lines as $i => $l) {
                $db->insert('entry_lines', ['entry_id' => $id, 'line_no' => $i + 1] + $l);
            }
            if (!empty($data['validate'])) {
                return self::validateEntry($db, $id);
            }
            return self::getEntry($db, $id);
        });
    }

    private static function normalizeLines(Db $db, array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $account = preg_replace('/\D/', '', (string) ($l['account'] ?? '')) ?? '';
            $debit = isset($l['debit_cents']) ? (int) $l['debit_cents'] : Money::toCents($l['debit'] ?? '');
            $credit = isset($l['credit_cents']) ? (int) $l['credit_cents'] : Money::toCents($l['credit'] ?? '');
            if ($account === '' && $debit === 0 && $credit === 0) {
                continue; // linha vazia
            }
            if ($account === '') {
                throw new UserError('Há uma linha com valor mas sem conta.');
            }
            if (!$db->value('SELECT 1 FROM accounts WHERE code = ?', [$account])) {
                throw new UserError("A conta {$account} não existe no plano de contas.");
            }
            if ($debit < 0 || $credit < 0) {
                throw new UserError('Os valores das linhas não podem ser negativos.');
            }
            if ($debit > 0 && $credit > 0) {
                throw new UserError("A linha da conta {$account} tem débito e crédito ao mesmo tempo.");
            }
            $vatBase = null;
            if (isset($l['vat_base_cents']) && $l['vat_base_cents'] !== null && $l['vat_base_cents'] !== '') {
                $vatBase = (int) $l['vat_base_cents'];
            } elseif (($l['vat_base'] ?? '') !== '') {
                $vatBase = Money::toCents($l['vat_base']);
            }
            $out[] = [
                'account' => $account,
                'third_party' => !empty($l['third_party']) ? (int) $l['third_party'] : null,
                'description' => ($l['description'] ?? '') !== '' ? (string) $l['description'] : null,
                'debit_cents' => $debit,
                'credit_cents' => $credit,
                'vat_code' => ($l['vat_code'] ?? '') !== '' ? (string) $l['vat_code'] : null,
                'vat_base_cents' => $vatBase,
                'cost_center' => ($l['cost_center'] ?? '') !== '' ? (string) $l['cost_center'] : null,
            ];
        }
        return $out;
    }

    public static function validateEntry(Db $db, int $id): array
    {
        return $db->transaction(static function () use ($db, $id) {
            $e = self::getEntry($db, $id);
            if ($e['status'] === 'validado') {
                throw new UserError('O lançamento já está validado.');
            }
            $fy = $db->one('SELECT * FROM fiscal_years WHERE year = ?', [$e['fiscal_year']]);
            if (!$fy || $fy['status'] !== 'aberto') {
                throw new UserError("O exercício {$e['fiscal_year']} está fechado.");
            }
            if ($fy['locked_until'] && $e['date'] <= $fy['locked_until']) {
                throw new UserError("O período até {$fy['locked_until']} está bloqueado.");
            }
            if (count($e['lines']) < 2) {
                throw new UserError('Um lançamento precisa de pelo menos duas linhas.');
            }
            $d = 0;
            $c = 0;
            foreach ($e['lines'] as $l) {
                if (!self::isMovementAccount($db, $l['account'])) {
                    throw new UserError("A conta {$l['account']} não é de movimento (tem subcontas) ou está inativa.");
                }
                if ($l['debit_cents'] == 0 && $l['credit_cents'] == 0) {
                    throw new UserError("A linha da conta {$l['account']} não tem valor.");
                }
                $d += (int) $l['debit_cents'];
                $c += (int) $l['credit_cents'];
            }
            if ($d !== $c) {
                throw new UserError('O lançamento não está equilibrado: débito ' . Money::format($d) . ' e crédito ' . Money::format($c) . '.');
            }
            $number = 1 + (int) $db->value('SELECT COALESCE(MAX(number),0) FROM entries WHERE fiscal_year = ? AND journal = ?', [$e['fiscal_year'], $e['journal']]);
            $prev = (string) ($db->value('SELECT hash FROM entries WHERE validated_seq IS NOT NULL ORDER BY validated_seq DESC LIMIT 1') ?? '');
            $now = date('Y-m-d H:i:s');
            $e['number'] = $number;
            $hash = self::hashEntry($e, $prev);
            $seq = 1 + (int) $db->value('SELECT MAX(validated_seq) FROM entries');
            $db->run("UPDATE entries SET status='validado', number=?, validated_at=?, prev_hash=?, hash=?, total_cents=?, validated_seq=? WHERE id=?",
                [$number, $now, $prev, $hash, $d, $seq, $id]);
            $db->audit('lancamento.validado', ['id' => $id, 'journal' => $e['journal'], 'number' => $number]);
            return array_merge($e, ['status' => 'validado', 'number' => $number, 'validated_at' => $now, 'prev_hash' => $prev, 'hash' => $hash, 'total_cents' => $d, 'validated_seq' => $seq]);
        });
    }

    public static function hashEntry(array $e, string $prev): string
    {
        $parts = [$prev, $e['id'], $e['fiscal_year'], $e['journal'], $e['number'], $e['date'], $e['description'], (string) ($e['document'] ?? '')];
        foreach ($e['lines'] as $l) {
            $parts[] = $l['account'] . ':' . (int) $l['debit_cents'] . ':' . (int) $l['credit_cents'] . ':' . (string) ($l['third_party'] ?? '');
        }
        return hash('sha256', implode('|', $parts));
    }

    /**
     * Verifica a cadeia de hash de todos os lançamentos validados, numa só leitura sequencial.
     * Guarda o resultado em meta.chain_check para a carteira não repetir a verificação sem lançamentos novos.
     */
    public static function verifyChain(Db $db): array
    {
        $st = $db->pdo->query("SELECT e.id, e.fiscal_year, e.journal, e.number, e.date, e.description, e.document, e.hash, e.prev_hash, e.validated_seq,
                                      l.account, l.debit_cents, l.credit_cents, l.third_party
                               FROM entries e JOIN entry_lines l ON l.entry_id = e.id
                               WHERE e.status = 'validado' ORDER BY e.validated_seq, l.line_no");
        $prev = '';
        $count = 0;
        $cur = null;
        $result = null;
        $check = static function (array $e) use (&$prev, &$count): bool {
            if ((string) $e['prev_hash'] !== $prev || self::hashEntry($e, $prev) !== $e['hash']) {
                return false;
            }
            $prev = (string) $e['hash'];
            $count++;
            return true;
        };
        while (($r = $st->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if ($cur === null || $cur['id'] !== $r['id']) {
                if ($cur !== null && !$check($cur)) {
                    $result = ['ok' => false, 'count' => $count, 'broken_at' => (int) $cur['id']];
                    break;
                }
                $cur = $r + ['lines' => []];
            }
            $cur['lines'][] = ['account' => $r['account'], 'debit_cents' => $r['debit_cents'], 'credit_cents' => $r['credit_cents'], 'third_party' => $r['third_party']];
        }
        $st->closeCursor();
        if ($result === null && $cur !== null && !$check($cur)) {
            $result = ['ok' => false, 'count' => $count, 'broken_at' => (int) $cur['id']];
        }
        // lançamentos validados sem linhas também quebram a cadeia
        if ($result === null && $count !== (int) $db->value("SELECT COUNT(*) FROM entries WHERE status = 'validado'")) {
            $result = ['ok' => false, 'count' => $count, 'broken_at' => (int) $db->value("SELECT id FROM entries e WHERE status = 'validado' AND NOT EXISTS (SELECT 1 FROM entry_lines l WHERE l.entry_id = e.id) LIMIT 1")];
        }
        $result ??= ['ok' => true, 'count' => $count];
        $db->setMeta('chain_check', json_encode(['seq' => (int) $db->value('SELECT MAX(validated_seq) FROM entries'), 'ok' => $result['ok'], 'at' => date('Y-m-d H:i:s')]));
        return $result;
    }

    /** Resultado da última verificação, se ainda válido (sem lançamentos validados depois dela e com menos de 7 dias); senão verifica de novo. */
    public static function chainStatus(Db $db): array
    {
        $c = json_decode((string) $db->meta('chain_check'), true);
        if (is_array($c) && $c['ok'] && (int) $c['seq'] === (int) $db->value('SELECT MAX(validated_seq) FROM entries') && strtotime((string) $c['at']) > strtotime('-7 days')) {
            return ['ok' => true, 'cached' => true, 'at' => $c['at']];
        }
        return self::verifyChain($db);
    }

    public static function deleteDraft(Db $db, int $id): void
    {
        $st = $db->value('SELECT status FROM entries WHERE id = ?', [$id]);
        if ($st !== 'rascunho') {
            throw new UserError('Só é possível apagar lançamentos em rascunho.');
        }
        $db->run('DELETE FROM entries WHERE id = ?', [$id]);
        $db->audit('lancamento.apagado', ['id' => $id]);
    }

    /** Estorno: novo lançamento validado com débitos e créditos trocados. */
    public static function reverseEntry(Db $db, int $id, ?string $date = null): array
    {
        return $db->transaction(static function () use ($db, $id, $date) {
            $e = self::getEntry($db, $id);
            if ($e['status'] !== 'validado') {
                throw new UserError('Só é possível estornar lançamentos validados.');
            }
            if ($e['reversed_by']) {
                throw new UserError('Este lançamento já foi estornado.');
            }
            $lines = array_map(static fn($l) => [
                'account' => $l['account'],
                'third_party' => $l['third_party'],
                'description' => $l['description'],
                'debit_cents' => (int) $l['credit_cents'],
                'credit_cents' => (int) $l['debit_cents'],
                'vat_code' => $l['vat_code'],
                'vat_base_cents' => $l['vat_base_cents'] !== null ? -(int) $l['vat_base_cents'] : null,
                'cost_center' => $l['cost_center'],
            ], $e['lines']);
            $new = self::saveEntry($db, [
                'date' => $date ?: $e['date'],
                'journal' => $e['journal'],
                'description' => 'Estorno de ' . $e['journal'] . '/' . $e['number'] . ': ' . $e['description'],
                'document' => $e['document'],
                'source' => 'estorno',
                'lines' => $lines,
                'validate' => true,
            ]);
            $db->run('UPDATE entries SET reverses = ? WHERE id = ?', [$id, $new['id']]);
            $db->run('UPDATE entries SET reversed_by = ? WHERE id = ?', [$new['id'], $id]);
            return self::getEntry($db, (int) $new['id']);
        });
    }
}
