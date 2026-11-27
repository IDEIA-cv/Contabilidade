<?php
declare(strict_types=1);

namespace Ideia;

use Ideia\Import\Csv;

/**
 * Reconciliação bancária: importação de extratos (CSV/Excel guardado como CSV dos bancos locais),
 * conciliação automática e manual com os movimentos contabilísticos da conta de depósitos, e mapa de reconciliação.
 *
 * Convenção: no extrato, amount_cents > 0 é uma entrada no banco (débito da conta 12x na contabilidade).
 */
final class Bank
{
    public const TEMPLATE = ['data', 'descricao', 'referencia', 'debito', 'credito', 'saldo'];

    private const ALIASES = [
        'data' => ['data', 'data_mov', 'data_movimento', 'data_lancamento', 'data_operacao', 'date', 'data_valor', 'dt_mov', 'dt_movimento'],
        'descricao' => ['descricao', 'descritivo', 'movimento', 'historico', 'designacao', 'description', 'detalhe', 'descricao_do_movimento', 'operacao'],
        'referencia' => ['referencia', 'ref', 'documento', 'n_documento', 'num_documento', 'n_doc', 'reference', 'cheque', 'n_cheque'],
        'debito' => ['debito', 'debitos', 'levantamento', 'levantamentos', 'saida', 'saidas', 'debit', 'pagamentos'],
        'credito' => ['credito', 'creditos', 'deposito', 'depositos', 'entrada', 'entradas', 'credit', 'recebimentos'],
        'montante' => ['montante', 'valor', 'importancia', 'amount', 'quantia', 'valor_ecv', 'montante_ecv'],
        'saldo' => ['saldo', 'saldo_contabilistico', 'saldo_disponivel', 'saldo_apos_movimento', 'balance', 'saldo_final'],
    ];

    /** Dias de tolerância entre a data do banco e a da contabilidade na conciliação automática. */
    public const AUTO_DAYS = 7;

    // ------------------------------------------------------------------ contas

    /** Contas de depósitos (12, 13, 14) de movimento, com indicadores de reconciliação. */
    public static function accounts(Db $db): array
    {
        $rows = $db->all("SELECT a.code, a.name FROM accounts a WHERE (a.code LIKE '12%' OR a.code LIKE '13%' OR a.code LIKE '14%') AND a.active = 1
                          AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent = a.code) ORDER BY a.code");
        foreach ($rows as &$r) {
            $r['bank_lines'] = (int) $db->value('SELECT COUNT(*) FROM bank_lines WHERE account = ?', [$r['code']]);
            $r['pending'] = (int) $db->value('SELECT COUNT(*) FROM bank_lines WHERE account = ? AND match_id IS NULL', [$r['code']]);
            $r['last_date'] = $db->value('SELECT MAX(date) FROM bank_lines WHERE account = ?', [$r['code']]);
        }
        return $rows;
    }

    private static function checkAccount(Db $db, string $account): void
    {
        if (!preg_match('/^1[234]/', $account) || !Ledger::isMovementAccount($db, $account)) {
            throw new UserError('Escolha uma conta de depósitos de movimento (12x, 13x ou 14x).');
        }
    }

    // ------------------------------------------------------------------ importação

    public static function template(): string
    {
        return "\xEF\xBB\xBF" . implode(';', self::TEMPLATE) . "\r\n"
            . "02/01/2025;Depósito de cheque;CHQ 1234;;150.000,00;1.150.000,00\r\n"
            . "05/01/2025;Pagamento a fornecedor;TRF 778;23.000,00;;1.127.000,00\r\n"
            . "31/01/2025;Comissão de manutenção de conta;;350,00;;1.126.650,00\r\n";
    }

    /**
     * Lê o extrato. Aceita linhas de cabeçalho do banco antes da tabela, separador ; , ou tabulação,
     * Windows-1252, colunas débito/crédito separadas ou uma coluna de montante com sinal.
     * @return array{lines: array, errors: array, columns: array}
     */
    public static function parse(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $raw = array_values(array_filter(explode("\n", $content), static fn($l) => trim($l) !== ''));
        if (!$raw) {
            throw new UserError('O ficheiro está vazio.');
        }
        // procura a linha de cabeçalho nas primeiras 20 linhas
        $header = null;
        $cols = [];
        $sep = ';';
        foreach (array_slice($raw, 0, 20, true) as $idx => $line) {
            $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
            arsort($counts);
            $s = (string) array_key_first($counts);
            $cells = str_getcsv($line, $s, '"', '');
            $found = [];
            foreach ($cells as $i => $c) {
                $key = self::norm((string) $c);
                foreach (self::ALIASES as $want => $aliases) {
                    if (!in_array($want, $found, true) && in_array($key, $aliases, true)) {
                        $found[$i] = $want;
                        break;
                    }
                }
            }
            if (in_array('data', $found, true) && (in_array('montante', $found, true) || in_array('debito', $found, true) || in_array('credito', $found, true))) {
                $header = $idx;
                $cols = $found;
                $sep = $s;
                break;
            }
        }
        if ($header === null) {
            throw new UserError('Não encontrei a linha de cabeçalho do extrato. São precisas as colunas "Data" e "Montante" (ou "Débito" e "Crédito"). Descarregue o modelo.');
        }
        $lines = [];
        $errors = [];
        foreach (array_slice($raw, $header + 1, null, true) as $idx => $line) {
            $cells = str_getcsv($line, $sep, '"', '');
            $row = [];
            foreach ($cols as $i => $name) {
                $row[$name] = trim((string) ($cells[$i] ?? ''));
            }
            $n = $idx + 1;
            if (($row['data'] ?? '') === '' && implode('', $row) === '') {
                continue;
            }
            $date = Csv::date($row['data'] ?? '');
            if (!$date) {
                // linhas de totais ou rodapé do banco ("Saldo final", "Total") são ignoradas sem erro
                if (preg_match('/saldo|total/i', implode(' ', $row))) {
                    continue;
                }
                $errors[] = ['line' => $n, 'message' => 'Data inválida: "' . ($row['data'] ?? '') . '"'];
                continue;
            }
            try {
                if (isset($row['montante']) && $row['montante'] !== '') {
                    $amount = Money::toCents($row['montante']);
                } else {
                    $amount = Money::toCents($row['credito'] ?? '') - Money::toCents($row['debito'] ?? '');
                }
                $balance = isset($row['saldo']) && $row['saldo'] !== '' ? Money::toCents($row['saldo']) : null;
            } catch (UserError $e) {
                $errors[] = ['line' => $n, 'message' => $e->getMessage()];
                continue;
            }
            if ($amount === 0) {
                continue; // linhas informativas sem valor
            }
            $desc = ($row['descricao'] ?? '') !== '' ? $row['descricao'] : 'Movimento bancário';
            $lines[] = ['line' => $n, 'date' => $date, 'description' => mb_substr($desc, 0, 250), 'reference' => ($row['referencia'] ?? '') ?: null,
                'amount_cents' => $amount, 'balance_cents' => $balance];
        }
        // impressão digital: data|montante|descrição|referência|ocorrência (dois movimentos iguais no mesmo dia continuam distintos)
        $seen = [];
        foreach ($lines as &$l) {
            $base = $l['date'] . '|' . $l['amount_cents'] . '|' . mb_strtolower($l['description']) . '|' . (string) $l['reference'];
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $l['fingerprint'] = hash('sha256', $base . '|' . $seen[$base]);
        }
        unset($l);
        usort($lines, static fn($a, $b) => [$a['date'], $a['line']] <=> [$b['date'], $b['line']]);
        return ['lines' => $lines, 'errors' => $errors, 'columns' => array_values($cols)];
    }

    private static function norm(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = strtr($h, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', 'º' => '', 'ª' => '', '.' => '', '(' => '', ')' => '']);
        return trim(preg_replace('/[\s\/\-]+/', '_', $h) ?? $h, '_');
    }

    /** Pré-visualiza ou grava um extrato. Movimentos já importados (mesma impressão digital) são ignorados. */
    public static function import(Db $db, string $account, string $content, bool $commit, string $filename = 'extrato.csv', ?string $opening = null): array
    {
        self::checkAccount($db, $account);
        $p = self::parse($content);
        $new = [];
        $dups = 0;
        foreach ($p['lines'] as $l) {
            if ($db->value('SELECT 1 FROM bank_lines WHERE account = ? AND fingerprint = ?', [$account, $l['fingerprint']])) {
                $dups++;
            } else {
                $new[] = $l;
            }
        }
        // saldo inicial: indicado pelo utilizador, ou deduzido da coluna saldo da primeira linha
        $openingCents = $opening !== null && trim($opening) !== '' ? Money::toCents($opening) : null;
        if ($openingCents === null && $p['lines'] && $p['lines'][0]['balance_cents'] !== null) {
            $openingCents = $p['lines'][0]['balance_cents'] - $p['lines'][0]['amount_cents'];
        }
        $in = array_sum(array_map(static fn($l) => max($l['amount_cents'], 0), $new));
        $out = array_sum(array_map(static fn($l) => min($l['amount_cents'], 0), $new));
        $result = [
            'account' => $account, 'columns' => $p['columns'], 'errors' => $p['errors'], 'total_lines' => count($p['lines']),
            'new_lines' => count($new), 'duplicates' => $dups, 'in_cents' => $in, 'out_cents' => $out,
            'date_from' => $new ? $new[0]['date'] : null, 'date_to' => $new ? $new[count($new) - 1]['date'] : null,
            'opening_cents' => $openingCents, 'sample' => array_slice($new, 0, 15), 'committed' => false, 'statement_id' => null,
        ];
        if (!$commit) {
            return $result;
        }
        if ($p['errors']) {
            throw new UserError('O extrato tem ' . count($p['errors']) . ' linha(s) com erros. Corrija o ficheiro e repita: nada foi gravado.');
        }
        if (!$new) {
            throw new UserError('Não há movimentos novos neste extrato (todos já tinham sido importados).');
        }
        $db->transaction(static function () use ($db, $account, $new, $filename, $openingCents, &$result) {
            $sid = $db->insert('bank_statements', ['account' => $account, 'filename' => $filename, 'imported_at' => date('Y-m-d H:i:s'),
                'date_from' => $result['date_from'], 'date_to' => $result['date_to'], 'opening_cents' => $openingCents, 'lines' => count($new)]);
            foreach ($new as $l) {
                $db->insert('bank_lines', ['statement_id' => $sid, 'account' => $account, 'date' => $l['date'], 'description' => $l['description'],
                    'reference' => $l['reference'], 'amount_cents' => $l['amount_cents'], 'balance_cents' => $l['balance_cents'], 'fingerprint' => $l['fingerprint']]);
            }
            $db->audit('extrato.importado', ['conta' => $account, 'ficheiro' => $filename, 'movimentos' => count($new)]);
            $result['statement_id'] = $sid;
        });
        $result['committed'] = true;
        $result['auto_matched'] = self::autoMatch($db, $account)['matched'];
        return $result;
    }

    public static function statements(Db $db, string $account): array
    {
        return $db->all('SELECT s.*, (SELECT COUNT(*) FROM bank_lines l WHERE l.statement_id = s.id AND l.match_id IS NOT NULL) AS matched
                         FROM bank_statements s WHERE s.account = ? ORDER BY s.id DESC', [$account]);
    }

    /** Apaga um extrato importado e desfaz as conciliações dos seus movimentos. */
    public static function deleteStatement(Db $db, int $id): void
    {
        $db->transaction(static function () use ($db, $id) {
            $s = $db->one('SELECT * FROM bank_statements WHERE id = ?', [$id]);
            if (!$s) {
                throw new UserError('Extrato não encontrado.');
            }
            foreach ($db->all('SELECT DISTINCT match_id FROM bank_lines WHERE statement_id = ? AND match_id IS NOT NULL', [$id]) as $m) {
                self::unmatch($db, (int) $m['match_id']);
            }
            $db->run('DELETE FROM bank_lines WHERE statement_id = ?', [$id]);
            $db->run('DELETE FROM bank_statements WHERE id = ?', [$id]);
            $db->audit('extrato.apagado', ['conta' => $s['account'], 'ficheiro' => $s['filename']]);
        });
    }

    // ------------------------------------------------------------------ pendentes e conciliação

    /**
     * Data a partir da qual se reconcilia: a indicada pelo utilizador ou, por omissão, o primeiro dia do mês
     * do primeiro movimento de extrato importado nesta conta. Movimentos contabilísticos anteriores
     * consideram-se refletidos no saldo inicial do extrato.
     */
    public static function startDate(Db $db, string $account): ?string
    {
        $set = $db->meta('bank_start_' . $account);
        if ($set) {
            return $set;
        }
        $d = $db->value('SELECT MIN(date) FROM bank_lines WHERE account = ?', [$account]);
        return $d ? substr((string) $d, 0, 8) . '01' : null;
    }

    public static function setStartDate(Db $db, string $account, ?string $date): void
    {
        self::checkAccount($db, $account);
        if ($date !== null && $date !== '' && !Csv::date($date)) {
            throw new UserError('Data inválida.');
        }
        $db->setMeta('bank_start_' . $account, $date ? Csv::date($date) : null);
    }

    /** Movimentos contabilísticos da conta ainda não conciliados (desde o início do extrato, sem aberturas nem apuramentos). */
    private static function pendingLedger(Db $db, string $account, ?string $to = null): array
    {
        $start = self::startDate($db, $account);
        if (!$start) {
            return [];
        }
        $sql = "SELECT l.id, l.entry_id, e.date, e.journal, e.number, e.document, COALESCE(NULLIF(l.description, ''), e.description) AS description,
                       l.debit_cents - l.credit_cents AS amount_cents
                FROM entry_lines l JOIN entries e ON e.id = l.entry_id JOIN journals j ON j.code = e.journal
                WHERE e.status = 'validado' AND l.account = ? AND j.kind = 'normal' AND e.date >= ?
                  AND NOT EXISTS (SELECT 1 FROM bank_match_ledger m WHERE m.entry_line_id = l.id)";
        $args = [$account, $start];
        if ($to) {
            $sql .= ' AND e.date <= ?';
            $args[] = $to;
        }
        return $db->all($sql . ' ORDER BY e.date, l.id', $args);
    }

    private static function pendingBank(Db $db, string $account, ?string $to = null): array
    {
        $sql = 'SELECT id, date, description, reference, amount_cents, balance_cents FROM bank_lines WHERE account = ? AND match_id IS NULL';
        $args = [$account];
        if ($to) {
            $sql .= ' AND date <= ?';
            $args[] = $to;
        }
        return $db->all($sql . ' ORDER BY date, id', $args);
    }

    public static function pending(Db $db, string $account): array
    {
        self::checkAccount($db, $account);
        $bank = self::pendingBank($db, $account);
        $ledger = self::pendingLedger($db, $account);
        $today = date('Y-m-d');
        return [
            'account' => $account,
            'start' => self::startDate($db, $account),
            'bank' => $bank,
            'ledger' => $ledger,
            'summary' => self::report($db, $account, (string) ($db->value('SELECT MAX(date) FROM bank_lines WHERE account = ?', [$account]) ?: $today)),
            'matched_count' => (int) $db->value('SELECT COUNT(*) FROM bank_lines WHERE account = ? AND match_id IS NOT NULL', [$account]),
        ];
    }

    /** Concilia um conjunto de movimentos do extrato com um conjunto de movimentos contabilísticos de soma igual. */
    public static function match(Db $db, string $account, array $bankIds, array $ledgerIds, bool $auto = false): int
    {
        self::checkAccount($db, $account);
        $bankIds = array_values(array_unique(array_map('intval', $bankIds)));
        $ledgerIds = array_values(array_unique(array_map('intval', $ledgerIds)));
        if (!$bankIds || !$ledgerIds) {
            throw new UserError('Selecione pelo menos um movimento do extrato e um da contabilidade.');
        }
        return $db->transaction(static function () use ($db, $account, $bankIds, $ledgerIds, $auto) {
            $bankSum = 0;
            foreach ($bankIds as $id) {
                $b = $db->one('SELECT * FROM bank_lines WHERE id = ?', [$id]);
                if (!$b || $b['account'] !== $account) {
                    throw new UserError('Movimento do extrato inválido.');
                }
                if ($b['match_id']) {
                    throw new UserError('O movimento do extrato de ' . $b['date'] . ' já está conciliado.');
                }
                $bankSum += (int) $b['amount_cents'];
            }
            $ledgerSum = 0;
            foreach ($ledgerIds as $id) {
                $l = $db->one("SELECT l.*, e.status FROM entry_lines l JOIN entries e ON e.id = l.entry_id WHERE l.id = ?", [$id]);
                if (!$l || $l['account'] !== $account || $l['status'] !== 'validado') {
                    throw new UserError('Movimento contabilístico inválido (tem de ser um lançamento validado nesta conta).');
                }
                if ($db->value('SELECT 1 FROM bank_match_ledger WHERE entry_line_id = ?', [$id])) {
                    throw new UserError('Um dos movimentos contabilísticos já está conciliado.');
                }
                $ledgerSum += (int) $l['debit_cents'] - (int) $l['credit_cents'];
            }
            if ($bankSum !== $ledgerSum) {
                throw new UserError('Os totais não coincidem: extrato ' . Money::format($bankSum) . ', contabilidade ' . Money::format($ledgerSum) . '.');
            }
            $mid = $db->insert('bank_matches', ['account' => $account, 'created_at' => date('Y-m-d H:i:s'), 'auto' => $auto ? 1 : 0]);
            $db->run('UPDATE bank_lines SET match_id = ? WHERE id IN (' . implode(',', $bankIds) . ')', [$mid]);
            foreach ($ledgerIds as $id) {
                $db->insert('bank_match_ledger', ['match_id' => $mid, 'entry_line_id' => $id]);
            }
            return $mid;
        });
    }

    public static function unmatch(Db $db, int $matchId): void
    {
        $db->transaction(static function () use ($db, $matchId) {
            $db->run('UPDATE bank_lines SET match_id = NULL WHERE match_id = ?', [$matchId]);
            $db->run('DELETE FROM bank_match_ledger WHERE match_id = ?', [$matchId]);
            $db->run('DELETE FROM bank_matches WHERE id = ?', [$matchId]);
        });
    }

    /** Conciliações feitas (mais recentes primeiro), para consulta e para desfazer. */
    public static function matches(Db $db, string $account, int $limit = 100): array
    {
        $out = [];
        foreach ($db->all('SELECT * FROM bank_matches WHERE account = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$account]) as $m) {
            $m['bank'] = $db->all('SELECT id, date, description, reference, amount_cents FROM bank_lines WHERE match_id = ? ORDER BY date', [(int) $m['id']]);
            $m['ledger'] = $db->all("SELECT l.id, l.entry_id, e.date, e.journal, e.number, COALESCE(NULLIF(l.description, ''), e.description) AS description, l.debit_cents - l.credit_cents AS amount_cents
                FROM bank_match_ledger m JOIN entry_lines l ON l.id = m.entry_line_id JOIN entries e ON e.id = l.entry_id WHERE m.match_id = ? ORDER BY e.date", [(int) $m['id']]);
            $out[] = $m;
        }
        return $out;
    }

    /**
     * Conciliação automática 1 para 1: mesmo valor e datas a até AUTO_DAYS dias.
     * Só concilia quando há um candidato claro (único, ou o único mais próximo / com a mesma referência).
     */
    public static function autoMatch(Db $db, string $account): array
    {
        self::checkAccount($db, $account);
        $ledger = self::pendingLedger($db, $account);
        $taken = [];
        $matched = 0;
        foreach (self::pendingBank($db, $account) as $b) {
            $cands = [];
            foreach ($ledger as $l) {
                if (isset($taken[$l['id']]) || (int) $l['amount_cents'] !== (int) $b['amount_cents']) {
                    continue;
                }
                $days = abs((strtotime($l['date']) - strtotime($b['date'])) / 86400);
                if ($days <= self::AUTO_DAYS) {
                    $refHit = $b['reference'] && $l['document'] && (stripos((string) $l['document'], (string) $b['reference']) !== false || stripos((string) $b['reference'], (string) $l['document']) !== false);
                    $cands[] = ['l' => $l, 'days' => $days, 'ref' => $refHit];
                }
            }
            if (!$cands) {
                continue;
            }
            $refs = array_values(array_filter($cands, static fn($c) => $c['ref']));
            if (count($refs) === 1) {
                $pick = $refs[0];
            } else {
                usort($cands, static fn($x, $y) => $x['days'] <=> $y['days']);
                if (count($cands) > 1 && $cands[0]['days'] === $cands[1]['days']) {
                    continue; // ambíguo: fica para conciliação manual
                }
                $pick = $cands[0];
            }
            self::match($db, $account, [(int) $b['id']], [(int) $pick['l']['id']], true);
            $taken[$pick['l']['id']] = true;
            $matched++;
        }
        if ($matched) {
            $db->audit('banco.conciliacao_automatica', ['conta' => $account, 'conciliados' => $matched]);
        }
        return ['matched' => $matched];
    }

    /**
     * Cria e valida um lançamento a partir de um movimento do extrato que falta na contabilidade
     * (comissões, juros, transferências) e concilia-o logo.
     */
    public static function createEntry(Db $db, int $bankLineId, string $counterpart, ?string $description = null, ?int $thirdParty = null): array
    {
        $b = $db->one('SELECT * FROM bank_lines WHERE id = ?', [$bankLineId]);
        if (!$b) {
            throw new UserError('Movimento do extrato não encontrado.');
        }
        if ($b['match_id']) {
            throw new UserError('Este movimento já está conciliado.');
        }
        if ($counterpart === $b['account']) {
            throw new UserError('A contrapartida tem de ser outra conta.');
        }
        return $db->transaction(static function () use ($db, $b, $counterpart, $description, $thirdParty) {
            $amt = (int) $b['amount_cents'];
            $desc = trim((string) $description) ?: (string) $b['description'];
            $entry = Ledger::saveEntry($db, ['date' => $b['date'], 'journal' => 'BCO', 'description' => $desc, 'document' => $b['reference'], 'source' => 'banco', 'validate' => true, 'lines' => [
                ['account' => $b['account'], 'debit_cents' => max($amt, 0), 'credit_cents' => max(-$amt, 0)],
                ['account' => $counterpart, 'debit_cents' => max(-$amt, 0), 'credit_cents' => max($amt, 0), 'third_party' => $thirdParty ?: null],
            ]]);
            $lineId = (int) $db->value('SELECT id FROM entry_lines WHERE entry_id = ? AND account = ? ORDER BY line_no LIMIT 1', [(int) $entry['id'], $b['account']]);
            self::match($db, (string) $b['account'], [(int) $b['id']], [$lineId]);
            return $entry;
        });
    }

    // ------------------------------------------------------------------ mapa de reconciliação

    /**
     * Mapa de reconciliação à data: parte do saldo do extrato e chega ao saldo contabilístico
     * através dos movimentos por conciliar de cada lado.
     */
    public static function report(Db $db, string $account, string $date): array
    {
        self::checkAccount($db, $account);
        // saldo contabilístico: todos os movimentos validados até à data, no exercício da data (inclui a abertura)
        // exercício da data; se ainda não existir (ex.: hoje, antes de abrir o novo ano), o último exercício anterior
        $year = (int) ($db->value('SELECT MAX(year) FROM fiscal_years WHERE year <= ?', [(int) substr($date, 0, 4)]) ?? substr($date, 0, 4));
        $book = (int) $db->value("SELECT COALESCE(SUM(debit_cents - credit_cents), 0) FROM v_movements WHERE account = ? AND fiscal_year = ? AND date <= ?", [$account, $year, $date]);
        // saldo do extrato: última linha com saldo indicado, ou saldo inicial + movimentos
        $bankBalance = null;
        $last = $db->one('SELECT balance_cents, date, id FROM bank_lines WHERE account = ? AND date <= ? AND balance_cents IS NOT NULL ORDER BY date DESC, id DESC LIMIT 1', [$account, $date]);
        $opening = $db->one('SELECT opening_cents, date_from FROM bank_statements WHERE account = ? AND opening_cents IS NOT NULL ORDER BY date_from LIMIT 1', [$account]);
        $source = null;
        if ($last) {
            // movimentos do mesmo dia depois da linha com saldo (ex.: extratos sem saldo noutra importação)
            $after = (int) $db->value('SELECT COALESCE(SUM(amount_cents), 0) FROM bank_lines WHERE account = ? AND date <= ? AND (date > ? OR (date = ? AND id > ?)) AND balance_cents IS NULL', [$account, $date, $last['date'], $last['date'], (int) $last['id']]);
            $bankBalance = (int) $last['balance_cents'] + $after;
            $source = 'saldo indicado no extrato';
        } elseif ($opening) {
            $bankBalance = (int) $opening['opening_cents'] + (int) $db->value('SELECT COALESCE(SUM(amount_cents), 0) FROM bank_lines WHERE account = ? AND date <= ?', [$account, $date]);
            $source = 'saldo inicial + movimentos do extrato';
        }
        $pb = self::pendingBank($db, $account, $date);
        $pl = self::pendingLedger($db, $account, $date);
        $sum = static fn(array $rows, int $sign) => array_sum(array_map(static fn($r) => ($sign > 0 ? max((int) $r['amount_cents'], 0) : min((int) $r['amount_cents'], 0)), $rows));
        $ledgerIn = $sum($pl, 1);      // registado na contabilidade, ainda não no banco (depósitos em trânsito)
        $ledgerOut = $sum($pl, -1);    // pagamentos emitidos, ainda não debitados pelo banco (cheques em circulação)
        $bankIn = $sum($pb, 1);        // entradas no banco ainda não registadas
        $bankOut = $sum($pb, -1);      // saídas no banco ainda não registadas (comissões, débitos diretos)
        $computed = $bankBalance === null ? null : $bankBalance + $ledgerIn + $ledgerOut - $bankIn - $bankOut;
        return [
            'account' => $account,
            'account_name' => $db->value('SELECT name FROM accounts WHERE code = ?', [$account]),
            'date' => $date,
            'bank_balance' => $bankBalance,
            'bank_balance_source' => $source,
            'ledger_in' => $ledgerIn, 'ledger_out' => $ledgerOut, 'bank_in' => $bankIn, 'bank_out' => $bankOut,
            'computed_book' => $computed,
            'book_balance' => $book,
            'difference' => $computed === null ? null : $book - $computed,
            'pending_bank' => $pb,
            'pending_ledger' => $pl,
        ];
    }
}
