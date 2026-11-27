<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Inventário periódico: apuramento do custo das mercadorias vendidas e das matérias consumidas (CMVMC).
 *
 * As compras (faturas de fornecedor em 311/312) ficam na conta 31. No apuramento, a uma data:
 *   1) as compras passam para o inventário:            débito 32/33, crédito 311/312 (saldo da 31 até à data);
 *   2) o consumo sai do inventário para o gasto:       débito 611/612, crédito 32/33 = inventário inicial + compras − inventário final.
 * O inventário final é o valor contado (introduzido pelo utilizador). Pode repetir-se (por exemplo todos os meses);
 * cada apuramento é um lançamento validado (source = 'cmvmc') e anula-se por estorno, do mais recente para trás.
 */
final class Inventory
{
    /** compras → inventário → gasto, por natureza. */
    public const KINDS = [
        'mercadorias' => ['label' => 'Mercadorias', 'purchases' => '311', 'stock' => '32', 'cost' => '611'],
        'materias' => ['label' => 'Matérias-primas, subsidiárias e de consumo', 'purchases' => '312', 'stock' => '33', 'cost' => '612'],
    ];

    private static function balance(Db $db, string $prefix, string $until): int
    {
        $year = (int) substr($until, 0, 4);
        return (int) $db->value(
            "SELECT COALESCE(SUM(l.debit_cents - l.credit_cents), 0) FROM entry_lines l JOIN entries e ON e.id = l.entry_id JOIN journals j ON j.code = e.journal
             WHERE e.status = 'validado' AND e.fiscal_year = ? AND l.account LIKE ? AND e.date <= ? AND j.kind <> 'apuramento'",
            [$year, $prefix . '%', $until]
        );
    }

    /** Apuramentos do exercício ainda em vigor (não estornados). */
    public static function postings(Db $db, int $year): array
    {
        return $db->all(
            "SELECT id, date, journal, number, total_cents,
                    (SELECT COALESCE(SUM(l.debit_cents), 0) FROM entry_lines l WHERE l.entry_id = entries.id AND (l.account LIKE '611%' OR l.account LIKE '612%')) AS cost_cents
             FROM entries WHERE fiscal_year = ? AND source = 'cmvmc' AND status = 'validado' AND reversed_by IS NULL AND reverses IS NULL ORDER BY date, id",
            [$year]
        );
    }

    /** Situação a uma data: compras por transferir, inventário contabilístico, último apuramento, contas em falta. */
    public static function status(Db $db, int $year, ?string $date = null): array
    {
        $date = self::date($db, $year, $date);
        $kinds = [];
        foreach (self::KINDS as $k => $c) {
            $missing = array_values(array_filter([$c['purchases'], $c['stock'], $c['cost']], static fn($a) => !Ledger::isMovementAccount($db, $a)));
            $kinds[$k] = [
                'key' => $k, 'label' => $c['label'], 'accounts' => $c,
                'purchases' => self::balance($db, $c['purchases'], $date),
                'stock' => self::balance($db, $c['stock'], $date),
                'cost_ytd' => (int) $db->value(
                    "SELECT COALESCE(SUM(debit_cents - credit_cents), 0) FROM v_movements WHERE fiscal_year = ? AND account LIKE ? AND journal_kind <> 'apuramento'",
                    [$year, $c['cost'] . '%']
                ),
                'missing_accounts' => $missing,
            ];
        }
        $posts = self::postings($db, $year);
        return [
            'year' => $year, 'date' => $date, 'kinds' => $kinds, 'postings' => $posts,
            'pending' => array_sum(array_map(static fn($k) => $k['purchases'], $kinds)),
        ];
    }

    private static function date(Db $db, int $year, ?string $date): string
    {
        $fy = $db->one('SELECT * FROM fiscal_years WHERE year = ?', [$year]);
        if (!$fy) {
            throw new UserError("Não existe o exercício {$year}.");
        }
        $date = $date ?: (string) $fy['end_date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $fy['start_date'] || $date > $fy['end_date']) {
            throw new UserError("A data do apuramento tem de estar dentro do exercício {$year}.");
        }
        return $date;
    }

    /**
     * Cálculo sem gravar: para cada natureza com movimento, inventário inicial (à data), compras, inventário final e CMVMC.
     * $final: ['mercadorias' => '1.234,00', 'materias' => ...] (valor contado; vazio = 0).
     */
    public static function preview(Db $db, int $year, array $d): array
    {
        $st = self::status($db, $year, $d['date'] ?? null);
        $last = $st['postings'] ? end($st['postings']) : null;
        if ($last && $last['date'] >= $st['date']) {
            throw new UserError('Já há um apuramento nesta data ou depois (' . date('d/m/Y', strtotime($last['date'])) . '). Escolha uma data posterior ou anule esse apuramento.');
        }
        $rows = [];
        foreach ($st['kinds'] as $k => $x) {
            $raw = trim((string) ($d['final'][$k] ?? ($d['final_' . $k] ?? '')));
            $final = $raw === '' ? 0 : Money::toCents($raw);
            if ($final < 0) {
                throw new UserError("O inventário final de {$x['label']} não pode ser negativo.");
            }
            if ($x['purchases'] === 0 && $x['stock'] === 0 && $final === 0) {
                continue;
            }
            if ($x['missing_accounts']) {
                throw new UserError('Faltam contas de movimento para ' . mb_strtolower($x['label']) . ': ' . implode(', ', $x['missing_accounts']) . '.');
            }
            $cost = $x['stock'] + $x['purchases'] - $final;
            if ($cost < 0) {
                throw new UserError("O inventário final de {$x['label']} (" . Money::format($final) . ') é maior do que o inventário inicial mais as compras (' . Money::format($x['stock'] + $x['purchases']) . '). Confirme a contagem ou registe as compras em falta.');
            }
            $rows[] = ['key' => $k, 'label' => $x['label'], 'accounts' => $x['accounts'], 'initial' => $x['stock'], 'purchases' => $x['purchases'], 'final' => $final, 'cost' => $cost];
        }
        if (!$rows) {
            throw new UserError('Não há compras nem inventário de mercadorias ou matérias para apurar.');
        }
        return ['year' => $year, 'date' => $st['date'], 'rows' => $rows, 'total_cost' => array_sum(array_column($rows, 'cost'))];
    }

    /** Lança o apuramento (um lançamento validado no diário de operações diversas). */
    public static function post(Db $db, int $year, array $d): array
    {
        return $db->transaction(static function () use ($db, $year, $d) {
            $p = self::preview($db, $year, $d);
            $lines = [];
            foreach ($p['rows'] as $r) {
                $a = $r['accounts'];
                if ($r['purchases'] > 0) {
                    $lines[] = ['account' => $a['stock'], 'debit_cents' => $r['purchases'], 'credit_cents' => 0, 'description' => 'Compras para inventário'];
                    $lines[] = ['account' => $a['purchases'], 'debit_cents' => 0, 'credit_cents' => $r['purchases'], 'description' => 'Compras para inventário'];
                } elseif ($r['purchases'] < 0) { // mais notas de crédito do que compras desde o último apuramento
                    $lines[] = ['account' => $a['purchases'], 'debit_cents' => -$r['purchases'], 'credit_cents' => 0, 'description' => 'Devoluções de compras'];
                    $lines[] = ['account' => $a['stock'], 'debit_cents' => 0, 'credit_cents' => -$r['purchases'], 'description' => 'Devoluções de compras'];
                }
                if ($r['cost'] > 0) {
                    $lines[] = ['account' => $a['cost'], 'debit_cents' => $r['cost'], 'credit_cents' => 0, 'description' => 'Custo de ' . mb_strtolower($r['label'])];
                    $lines[] = ['account' => $a['stock'], 'debit_cents' => 0, 'credit_cents' => $r['cost'], 'description' => 'Custo de ' . mb_strtolower($r['label'])];
                }
            }
            if (!$lines) {
                throw new UserError('O apuramento não tem valores a lançar (sem compras e sem consumo).');
            }
            $e = Ledger::saveEntry($db, ['date' => $p['date'], 'journal' => 'DIV', 'description' => 'Apuramento do CMVMC (inventário final ' . date('d/m/Y', strtotime($p['date'])) . ')',
                'document' => 'CMVMC ' . $p['date'], 'source' => 'cmvmc', 'validate' => true, 'lines' => $lines]);
            $db->audit('inventario.apurado', ['year' => $year, 'date' => $p['date'], 'entry' => $e['id'], 'cmvmc' => $p['total_cost'], 'final' => array_column($p['rows'], 'final', 'key')]);
            return $e + ['preview' => $p];
        });
    }

    /** Anula o apuramento mais recente do exercício (estorno). */
    public static function unpost(Db $db, int $year, int $entryId): array
    {
        $posts = self::postings($db, $year);
        $last = $posts ? end($posts) : null;
        if (!$last || (int) $last['id'] !== $entryId) {
            throw new UserError('Só pode anular o apuramento mais recente do exercício.');
        }
        $r = Ledger::reverseEntry($db, $entryId, (string) $last['date']);
        $db->audit('inventario.anulado', ['year' => $year, 'entry' => $entryId]);
        return $r;
    }
}
