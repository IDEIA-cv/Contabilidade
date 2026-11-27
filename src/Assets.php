<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Imobilizado: ativos fixos tangíveis (43x) e intangíveis (44x), depreciações e amortizações pelo método das quotas
 * constantes, em duodécimos (no ano de entrada em funcionamento e no ano do abate contam os meses de uso, incluindo o mês
 * de início e o mês do abate).
 *
 * - Plano: para cada exercício, a depreciação calculada (ou a lançada, se já foi lançada), acumulada e valor líquido.
 * - Lançar depreciações do exercício: um lançamento validado em 31/12 (diário DIV), débito 642/643, crédito 438/448,
 *   uma linha por bem. Anular: estorna esse lançamento e permite lançar de novo.
 * - Abate ou venda: depreciação do ano até ao mês do abate, saída do custo e das depreciações acumuladas, valor de venda
 *   na conta escolhida e ganho (788) ou perda (688).
 * - Mapa de depreciações e amortizações por exercício (base do mapa fiscal; as taxas sugeridas devem ser confirmadas pelo
 *   contabilista com a tabela legal em vigor).
 */
final class Assets
{
    /** Categorias com conta e taxa anual sugerida (pontos-base). */
    public const CATEGORIES = [
        'edificios' => ['name' => 'Edifícios e outras construções', 'account' => '432', 'rate_bp' => 200],
        'equip_basico' => ['name' => 'Equipamento básico (máquinas, ferramentas)', 'account' => '433', 'rate_bp' => 1250],
        'viaturas' => ['name' => 'Viaturas ligeiras', 'account' => '434', 'rate_bp' => 2500],
        'viaturas_pesadas' => ['name' => 'Viaturas pesadas e outro transporte', 'account' => '434', 'rate_bp' => 2000],
        'mobiliario' => ['name' => 'Mobiliário e equipamento de escritório', 'account' => '435', 'rate_bp' => 1250],
        'informatica' => ['name' => 'Equipamento informático', 'account' => '435', 'rate_bp' => 3333],
        'outros_tangiveis' => ['name' => 'Outros ativos fixos tangíveis', 'account' => '437', 'rate_bp' => 2000],
        'software' => ['name' => 'Programas de computador (intangível)', 'account' => '443', 'rate_bp' => 3333],
        'outros_intangiveis' => ['name' => 'Outros ativos intangíveis', 'account' => '442', 'rate_bp' => 2000],
    ];

    public static function meta(Db $db): array
    {
        $accounts = $db->all("SELECT a.code, a.name FROM accounts a WHERE (a.code LIKE '43%' OR a.code LIKE '44%') AND a.code NOT IN ('438','448')
            AND a.active = 1 AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent = a.code) ORDER BY a.code");
        return ['categories' => self::CATEGORIES, 'accounts' => $accounts];
    }

    private static function intangible(string $account): bool
    {
        return str_starts_with($account, '44');
    }

    /** Contas de depreciação acumulada e de gasto para a conta do bem. */
    public static function accountsFor(string $account): array
    {
        return self::intangible($account) ? ['accum' => '448', 'expense' => '643'] : ['accum' => '438', 'expense' => '642'];
    }

    public static function list(Db $db, array $f = []): array
    {
        $year = (int) ($f['year'] ?? date('Y'));
        $where = ['1=1'];
        $p = [];
        if (($f['status'] ?? '') !== '') {
            $where[] = 'status = ?';
            $p[] = $f['status'];
        }
        if (($f['category'] ?? '') !== '') {
            $where[] = 'category = ?';
            $p[] = $f['category'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(code LIKE ? OR description LIKE ? OR supplier_doc LIKE ? OR location LIKE ?)';
            array_push($p, ...array_fill(0, 4, '%' . $f['q'] . '%'));
        }
        $rows = [];
        $tot = ['cost' => 0, 'accum_start' => 0, 'year' => 0, 'accum_end' => 0, 'net' => 0];
        foreach ($db->all('SELECT * FROM fixed_assets WHERE ' . implode(' AND ', $where) . ' ORDER BY code', $p) as $a) {
            $r = self::yearRow($db, $a, $year);
            $rows[] = $a + ['category_name' => self::CATEGORIES[$a['category']]['name'] ?? $a['category']] + $r;
            if ($r['in_year']) {
                foreach (['cost', 'accum_start', 'year', 'accum_end', 'net'] as $k) {
                    $tot[$k] += $r[$k];
                }
            }
        }
        $posted = $db->one('SELECT COUNT(*) AS n, MAX(entry_id) AS entry_id FROM fixed_asset_depreciations d JOIN fixed_assets a ON a.id = d.asset_id
            WHERE d.year = ? AND d.entry_id IS NOT NULL AND (a.disposal_entry_id IS NULL OR d.entry_id <> a.disposal_entry_id)', [$year]);
        $pending = array_filter($rows, static fn($r) => $r['in_year'] && !$r['posted'] && $r['year'] > 0);
        return ['year' => $year, 'rows' => $rows, 'totals' => $tot, 'posted' => (int) $posted['n'], 'posted_entry' => $posted['entry_id'] ? (int) $posted['entry_id'] : null,
            'pending' => count($pending), 'pending_cents' => array_sum(array_column($pending, 'year'))];
    }

    public static function get(Db $db, int $id): array
    {
        $a = $db->one('SELECT f.*, t.name AS supplier FROM fixed_assets f LEFT JOIN third_parties t ON t.id = f.third_party WHERE f.id = ?', [$id]);
        if (!$a) {
            throw new UserError('Bem do imobilizado não encontrado.');
        }
        $a['category_name'] = self::CATEGORIES[$a['category']]['name'] ?? $a['category'];
        $a['account_name'] = $db->value('SELECT name FROM accounts WHERE code = ?', [$a['account']]);
        $a['plan'] = self::plan($db, $a);
        $a['disposal_entry'] = $a['disposal_entry_id'] ? $db->one('SELECT id, journal, number FROM entries WHERE id = ?', [$a['disposal_entry_id']]) : null;
        return $a;
    }

    /** Valor anual da quota (sem duodécimos). */
    private static function annual(array $a): int
    {
        return (int) round(((int) $a['cost_cents'] - (int) $a['residual_cents']) * (int) $a['rate_bp'] / 10000);
    }

    /**
     * Plano de depreciações, do ano de início até o bem ficar totalmente depreciado (ou abatido).
     * @return list<array{year:int, months:int, amount:int, accum:int, net:int, posted:bool, entry_id:?int}>
     */
    public static function plan(Db $db, array $a, ?int $until = null): array
    {
        $posted = [];
        foreach ($db->all('SELECT year, amount_cents, entry_id FROM fixed_asset_depreciations WHERE asset_id = ?', [$a['id']]) as $d) {
            $posted[(int) $d['year']] = $d;
        }
        $cost = (int) $a['cost_cents'];
        $base = $cost - (int) $a['residual_cents'];
        $annual = self::annual($a);
        $startY = (int) substr($a['start_date'], 0, 4);
        $startM = (int) substr($a['start_date'], 5, 2);
        $endY = $a['status'] === 'abatido' && $a['disposal_date'] ? (int) substr($a['disposal_date'], 0, 4) : null;
        $accum = (int) $a['prior_cents'];
        $firstY = $a['prior_year'] ? max($startY, (int) $a['prior_year'] + 1) : $startY;
        $out = [];
        for ($y = $firstY; ; $y++) {
            if ($endY !== null && $y > $endY) {
                break;
            }
            if ($until !== null && $y > $until) {
                break;
            }
            $from = $y === $startY ? $startM : 1;
            $to = $endY === $y ? (int) substr($a['disposal_date'], 5, 2) : 12;
            $months = max(0, $to - $from + 1);
            $calc = min((int) round($annual * $months / 12), max(0, $base - $accum));
            $p = $posted[$y] ?? null;
            $amount = $p ? (int) $p['amount_cents'] : $calc;
            $accum += $amount;
            $out[] = ['year' => $y, 'months' => $months, 'amount' => $amount, 'accum' => $accum, 'net' => $cost - $accum, 'posted' => (bool) $p, 'entry_id' => $p['entry_id'] ?? null];
            if ($accum >= $base && $until === null) {
                break;
            }
            if ($y > $startY + 200) {
                break; // segurança
            }
        }
        return $out;
    }

    /** Valores de um bem num exercício: custo, acumuladas no início, do ano, no fim, valor líquido. */
    private static function yearRow(Db $db, array $a, int $year): array
    {
        $cost = (int) $a['cost_cents'];
        $startY = (int) substr($a['start_date'], 0, 4);
        $acqY = (int) substr($a['acquisition_date'], 0, 4);
        $disposedBefore = $a['status'] === 'abatido' && (int) substr((string) $a['disposal_date'], 0, 4) < $year;
        $inYear = min($acqY, $startY) <= $year && !$disposedBefore;
        $accumStart = (int) $a['prior_cents'];
        $amount = 0;
        $posted = false;
        $priorYear = $a['prior_year'] ? (int) $a['prior_year'] : null;
        if ($priorYear !== null && $year <= $priorYear) {
            return ['in_year' => $inYear, 'cost' => $cost, 'accum_start' => $accumStart, 'year' => 0, 'accum_end' => $accumStart, 'net' => $cost - $accumStart, 'posted' => true, 'rate_bp' => (int) $a['rate_bp']];
        }
        foreach (self::plan($db, $a, $year) as $r) {
            if ($r['year'] < $year) {
                $accumStart = $r['accum'];
            } elseif ($r['year'] === $year) {
                $amount = $r['amount'];
                $posted = $r['posted'];
            }
        }
        $end = $accumStart + $amount;
        return ['in_year' => $inYear, 'cost' => $cost, 'accum_start' => $accumStart, 'year' => $amount, 'accum_end' => $end, 'net' => $cost - $end, 'posted' => $posted, 'rate_bp' => (int) $a['rate_bp']];
    }

    public static function save(Db $db, array $d): array
    {
        $id = !empty($d['id']) ? (int) $d['id'] : null;
        $cur = $id ? self::get($db, $id) : null;
        $cat = (string) ($d['category'] ?? '');
        if (!isset(self::CATEGORIES[$cat])) {
            throw new UserError('Escolha a categoria do bem.');
        }
        $desc = trim((string) ($d['description'] ?? ''));
        if ($desc === '') {
            throw new UserError('Indique a descrição do bem.');
        }
        $account = preg_replace('/\D/', '', (string) ($d['account'] ?? '')) ?: self::CATEGORIES[$cat]['account'];
        if (!preg_match('/^4[34]/', $account) || in_array(substr($account, 0, 3), ['438', '448'], true) || !$db->value('SELECT 1 FROM accounts WHERE code = ?', [$account])) {
            throw new UserError('A conta do bem tem de ser uma conta 43 (tangível) ou 44 (intangível), sem ser de depreciações acumuladas.');
        }
        if ($db->value('SELECT 1 FROM accounts WHERE parent = ?', [$account])) {
            throw new UserError("A conta {$account} tem subcontas: escolha uma conta de movimento.");
        }
        $date = static function (string $k, bool $required = true) use ($d): ?string {
            $v = (string) ($d[$k] ?? '');
            if ($v === '' && !$required) {
                return null;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                throw new UserError('Data inválida.');
            }
            return $v;
        };
        $acq = $date('acquisition_date');
        $start = $date('start_date', false) ?? $acq;
        if ($start < $acq) {
            throw new UserError('A entrada em funcionamento não pode ser anterior à aquisição.');
        }
        $cents = static fn(string $k) => isset($d[$k . '_cents']) ? (int) $d[$k . '_cents'] : (($d[$k] ?? '') !== '' ? Money::toCents((string) $d[$k]) : 0);
        $cost = $cents('cost');
        $residual = $cents('residual');
        $prior = $cents('prior');
        if ($cost <= 0) {
            throw new UserError('Indique o custo de aquisição.');
        }
        if ($residual < 0 || $residual >= $cost) {
            throw new UserError('O valor residual tem de ser menor do que o custo.');
        }
        if ($prior < 0 || $prior > $cost - $residual) {
            throw new UserError('As depreciações acumuladas anteriores não podem passar do valor a depreciar.');
        }
        $rate = isset($d['rate_bp']) && $d['rate_bp'] !== '' ? (int) $d['rate_bp'] : (int) round(((float) str_replace(',', '.', (string) ($d['rate'] ?? ''))) * 100);
        if ($rate <= 0) {
            $rate = self::CATEGORIES[$cat]['rate_bp'];
        }
        if ($rate > 10000) {
            throw new UserError('A taxa anual não pode passar de 100%.');
        }
        $priorYear = $prior > 0 ? (int) ($d['prior_year'] ?? 0) : null;
        if ($prior > 0 && ($priorYear < (int) substr($start, 0, 4) || $priorYear > 2100)) {
            throw new UserError('Indique o último exercício incluído nas depreciações acumuladas anteriores.');
        }
        $row = [
            'description' => mb_substr($desc, 0, 200), 'category' => $cat, 'account' => $account, 'acquisition_date' => $acq, 'start_date' => $start,
            'cost_cents' => $cost, 'residual_cents' => $residual, 'rate_bp' => $rate, 'prior_cents' => $prior, 'prior_year' => $priorYear,
            'third_party' => !empty($d['third_party']) ? (int) $d['third_party'] : null, 'supplier_doc' => trim((string) ($d['supplier_doc'] ?? '')) ?: null,
            'location' => trim((string) ($d['location'] ?? '')) ?: null, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
        ];
        return $db->transaction(static function () use ($db, $id, $cur, $row) {
            if ($cur) {
                if ($cur['status'] === 'abatido') {
                    throw new UserError('Um bem abatido não pode ser alterado.');
                }
                $hasPosted = (bool) $db->value('SELECT 1 FROM fixed_asset_depreciations WHERE asset_id = ? AND entry_id IS NOT NULL', [$id]);
                foreach (['account', 'acquisition_date', 'start_date', 'cost_cents', 'residual_cents', 'rate_bp', 'prior_cents', 'prior_year'] as $k) {
                    if ($hasPosted && (string) $cur[$k] !== (string) $row[$k]) {
                        throw new UserError('Este bem já tem depreciações lançadas: só pode mudar a descrição, o fornecedor, a localização e as notas. Para mudar valores, anule primeiro as depreciações lançadas.');
                    }
                }
                $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($row)));
                $db->run("UPDATE fixed_assets SET $sets WHERE id = ?", [...array_values($row), $id]);
                $db->audit('imobilizado.alterado', ['id' => $id, 'codigo' => $cur['code']]);
            } else {
                $n = (int) $db->value("SELECT COALESCE(MAX(CAST(substr(code, 3) AS INTEGER)), 0) FROM fixed_assets WHERE code LIKE 'IM%'") + 1;
                $row['code'] = sprintf('IM%04d', $n);
                $id = $db->insert('fixed_assets', $row);
                $db->audit('imobilizado.criado', ['id' => $id, 'codigo' => $row['code'], 'custo' => $row['cost_cents']]);
            }
            return self::get($db, $id);
        });
    }

    public static function delete(Db $db, int $id): void
    {
        $a = self::get($db, $id);
        if ($db->value('SELECT 1 FROM fixed_asset_depreciations WHERE asset_id = ? AND entry_id IS NOT NULL', [$id]) || $a['disposal_entry_id']) {
            throw new UserError('Este bem já tem lançamentos (depreciações ou abate) e não pode ser apagado.');
        }
        $db->run('DELETE FROM fixed_assets WHERE id = ?', [$id]);
        $db->audit('imobilizado.apagado', ['id' => $id, 'codigo' => $a['code']]);
    }

    /** O que seria lançado num exercício (pré-visualização). */
    public static function preview(Db $db, int $year): array
    {
        $lines = [];
        foreach ($db->all("SELECT * FROM fixed_assets WHERE status = 'ativo' OR (status = 'abatido' AND substr(disposal_date, 1, 4) > ?) ORDER BY code", [(string) $year]) as $a) {
            if ($db->value('SELECT 1 FROM fixed_asset_depreciations WHERE asset_id = ? AND year = ?', [$a['id'], $year])) {
                continue;
            }
            if ($a['prior_year'] && $year <= (int) $a['prior_year']) {
                continue;
            }
            foreach (self::plan($db, $a, $year) as $r) {
                if ($r['year'] === $year && $r['amount'] > 0) {
                    $acc = self::accountsFor($a['account']);
                    $lines[] = ['asset_id' => (int) $a['id'], 'code' => $a['code'], 'description' => $a['description'], 'months' => $r['months'], 'amount' => $r['amount']] + $acc;
                }
            }
        }
        // anos anteriores por lançar (avisa: devem ser lançados por ordem)
        $missing = [];
        foreach ($db->all("SELECT * FROM fixed_assets WHERE status = 'ativo'") as $a) {
            foreach (self::plan($db, $a, $year - 1) as $r) {
                if ($r['year'] < $year && !$r['posted'] && $r['amount'] > 0 && (!$a['prior_year'] || $r['year'] > (int) $a['prior_year'])) {
                    $missing[$r['year']] = true;
                }
            }
        }
        ksort($missing);
        return ['year' => $year, 'lines' => $lines, 'total' => array_sum(array_column($lines, 'amount')), 'missing_years' => array_keys($missing)];
    }

    /** Lança as depreciações/amortizações do exercício num único lançamento em 31/12. */
    public static function post(Db $db, int $year): array
    {
        $p = self::preview($db, $year);
        if ($p['missing_years']) {
            throw new UserError('Lance primeiro as depreciações de ' . implode(', ', $p['missing_years']) . '.');
        }
        if (!$p['lines']) {
            throw new UserError("Não há depreciações por lançar em {$year}.");
        }
        return $db->transaction(static function () use ($db, $year, $p) {
            $lines = [];
            $credit = [];
            foreach ($p['lines'] as $l) {
                $lines[] = ['account' => $l['expense'], 'debit_cents' => $l['amount'], 'credit_cents' => 0, 'description' => $l['code'] . ' ' . mb_substr($l['description'], 0, 80)];
                $credit[$l['accum']] = ($credit[$l['accum']] ?? 0) + $l['amount'];
            }
            foreach ($credit as $acc => $v) {
                $lines[] = ['account' => (string) $acc, 'debit_cents' => 0, 'credit_cents' => $v, 'description' => 'Depreciações e amortizações acumuladas'];
            }
            $e = Ledger::saveEntry($db, ['date' => "$year-12-31", 'journal' => 'DIV', 'description' => "Depreciações e amortizações do exercício {$year}",
                'document' => "IMOB {$year}", 'validate' => true, 'lines' => $lines]);
            foreach ($p['lines'] as $l) {
                $db->insert('fixed_asset_depreciations', ['asset_id' => $l['asset_id'], 'year' => $year, 'amount_cents' => $l['amount'], 'entry_id' => $e['id']]);
            }
            $db->audit('imobilizado.depreciacoes', ['ano' => $year, 'bens' => count($p['lines']), 'total' => $p['total'], 'lancamento' => $e['id']]);
            return ['entry' => ['id' => $e['id'], 'journal' => $e['journal'], 'number' => $e['number'] ?? null], 'count' => count($p['lines']), 'total' => $p['total']];
        });
    }

    /** Anula as depreciações lançadas de um exercício: estorna o lançamento e liberta o ano para lançar de novo. */
    public static function unpost(Db $db, int $year): array
    {
        $entries = array_column($db->all('SELECT DISTINCT d.entry_id FROM fixed_asset_depreciations d JOIN fixed_assets a ON a.id = d.asset_id
            WHERE d.year = ? AND d.entry_id IS NOT NULL AND (a.disposal_entry_id IS NULL OR d.entry_id <> a.disposal_entry_id)', [$year]), 'entry_id');
        if (!$entries) {
            throw new UserError("Não há depreciações lançadas em {$year}.");
        }
        if ($db->value('SELECT 1 FROM fixed_asset_depreciations WHERE year > ? AND entry_id IS NOT NULL', [$year])) {
            throw new UserError('Anule primeiro as depreciações dos exercícios seguintes.');
        }
        return $db->transaction(static function () use ($db, $year, $entries) {
            foreach ($entries as $eid) {
                Ledger::reverseEntry($db, (int) $eid, "$year-12-31");
                $db->run('DELETE FROM fixed_asset_depreciations WHERE entry_id = ? AND year = ?', [$eid, $year]);
            }
            $db->audit('imobilizado.depreciacoes_anuladas', ['ano' => $year]);
            return ['year' => $year, 'reversed' => count($entries)];
        });
    }

    /**
     * Abate (value = 0) ou venda. account: conta que recebe o valor de venda (ex.: 12 banco, 2781 devedores).
     * Lança a depreciação do ano até ao mês do abate, se ainda não lançada.
     */
    public static function dispose(Db $db, int $id, array $d): array
    {
        $a = self::get($db, $id);
        if ($a['status'] === 'abatido') {
            throw new UserError('Este bem já foi abatido.');
        }
        $date = (string) ($d['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $a['start_date']) {
            throw new UserError('Data de abate inválida (tem de ser depois da entrada em funcionamento).');
        }
        $year = (int) substr($date, 0, 4);
        $value = isset($d['value_cents']) ? (int) $d['value_cents'] : (($d['value'] ?? '') !== '' ? Money::toCents((string) $d['value']) : 0);
        $proceeds = preg_replace('/\D/', '', (string) ($d['account'] ?? ''));
        if ($value < 0) {
            throw new UserError('Valor de venda inválido.');
        }
        if ($value > 0 && (!$proceeds || !$db->value('SELECT 1 FROM accounts WHERE code = ?', [$proceeds]) || $db->value('SELECT 1 FROM accounts WHERE parent = ?', [$proceeds]))) {
            throw new UserError('Indique a conta de movimento onde entra o valor da venda (ex.: banco ou devedores).');
        }
        $reason = trim((string) ($d['reason'] ?? '')) ?: ($value > 0 ? 'Venda' : 'Abate');
        // anos anteriores têm de estar lançados
        foreach (self::plan($db, $a, $year - 1) as $r) {
            if ($r['year'] < $year && !$r['posted'] && $r['amount'] > 0 && (!$a['prior_year'] || $r['year'] > (int) $a['prior_year'])) {
                throw new UserError("Lance primeiro as depreciações de {$r['year']}.");
            }
        }
        if ($db->value('SELECT 1 FROM fixed_asset_depreciations WHERE asset_id = ? AND year >= ? AND entry_id IS NOT NULL', [$id, $year])) {
            throw new UserError("As depreciações de {$year} deste bem já foram lançadas para o ano inteiro. Anule-as primeiro (Imobilizado, exercício {$year}).");
        }
        return $db->transaction(static function () use ($db, $a, $id, $date, $year, $value, $proceeds, $reason) {
            $db->run("UPDATE fixed_assets SET status = 'abatido', disposal_date = ? WHERE id = ?", [$date, $id]); // para o plano contar só até ao mês do abate
            $a2 = $db->one('SELECT * FROM fixed_assets WHERE id = ?', [$id]);
            $yearAmount = 0;
            $accum = (int) $a['prior_cents'];
            foreach (self::plan($db, $a2) as $r) {
                if ($r['year'] === $year) {
                    $yearAmount = $r['amount'];
                }
                $accum = $r['accum'];
            }
            if (!self::plan($db, $a2)) {
                $accum = (int) $a['prior_cents'];
            }
            $acc = self::accountsFor($a['account']);
            $cost = (int) $a['cost_cents'];
            $net = $cost - $accum;
            $result = $value - $net;
            $lines = [];
            if ($yearAmount > 0) {
                $lines[] = ['account' => $acc['expense'], 'debit_cents' => $yearAmount, 'credit_cents' => 0, 'description' => "Depreciação de {$year} até ao abate"];
            }
            if ($accum > 0) {
                $lines[] = ['account' => $acc['accum'], 'debit_cents' => $accum - $yearAmount, 'credit_cents' => 0, 'description' => 'Depreciações acumuladas do bem'];
            }
            if ($value > 0) {
                $lines[] = ['account' => $proceeds, 'debit_cents' => $value, 'credit_cents' => 0, 'description' => 'Valor de venda'];
            }
            if ($result < 0) {
                $lines[] = ['account' => '688', 'debit_cents' => -$result, 'credit_cents' => 0, 'description' => 'Perda no abate/venda'];
            } elseif ($result > 0) {
                $lines[] = ['account' => '788', 'debit_cents' => 0, 'credit_cents' => $result, 'description' => 'Ganho na venda'];
            }
            $lines[] = ['account' => $a['account'], 'debit_cents' => 0, 'credit_cents' => $cost, 'description' => 'Saída do custo de aquisição'];
            $lines = array_values(array_filter($lines, static fn($l) => $l['debit_cents'] > 0 || $l['credit_cents'] > 0));
            $e = Ledger::saveEntry($db, ['date' => $date, 'journal' => 'DIV', 'description' => "{$reason} do bem {$a['code']} — " . mb_substr($a['description'], 0, 80),
                'document' => $a['code'], 'validate' => true, 'lines' => $lines]);
            if ($yearAmount > 0) {
                $db->run('INSERT OR REPLACE INTO fixed_asset_depreciations (asset_id, year, amount_cents, entry_id) VALUES (?, ?, ?, ?)', [$id, $year, $yearAmount, $e['id']]);
            }
            $db->run('UPDATE fixed_assets SET disposal_value_cents = ?, disposal_reason = ?, disposal_entry_id = ? WHERE id = ?', [$value, $reason, $e['id'], $id]);
            $db->audit('imobilizado.abatido', ['id' => $id, 'codigo' => $a['code'], 'valor' => $value, 'resultado' => $result, 'lancamento' => $e['id']]);
            return self::get($db, $id) + ['result_cents' => $result];
        });
    }
}
