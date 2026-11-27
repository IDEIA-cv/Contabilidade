<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Fecho do exercício (apuramento de resultados) e abertura do exercício seguinte.
 */
final class Closing
{
    /**
     * Fecha o exercício:
     *  1. salda as contas das classes 6 e 7 contra 811 (resultado antes de impostos);
     *  2. transfere 811 e 812 para 818 (resultado líquido);
     *  3. marca o exercício como fechado.
     */
    public static function closeYear(Db $db, int $year): array
    {
        return $db->transaction(static function () use ($db, $year) {
            $fy = $db->one('SELECT * FROM fiscal_years WHERE year = ?', [$year]);
            if (!$fy) {
                throw new UserError('Exercício inexistente.');
            }
            if ($fy['status'] === 'fechado') {
                throw new UserError("O exercício {$year} já está fechado.");
            }
            $drafts = (int) $db->value("SELECT COUNT(*) FROM entries WHERE fiscal_year = ? AND status = 'rascunho'", [$year]);
            if ($drafts > 0) {
                throw new UserError("Há {$drafts} lançamento(s) em rascunho no exercício {$year}. Valide-os ou apague-os antes de fechar.");
            }
            $pending = (int) $db->value(
                "SELECT COALESCE(SUM(debit_cents - credit_cents), 0) FROM v_movements WHERE fiscal_year = ? AND account LIKE '31%' AND journal_kind <> 'apuramento'",
                [$year]
            );
            if ($pending !== 0) {
                throw new UserError('Há ' . Money::format($pending) . " de compras na conta 31 que ainda não passaram para o inventário. Faça primeiro o apuramento do custo das mercadorias vendidas (Exercícios e fecho → Inventário e CMVMC), com o inventário final de {$year}.");
            }
            foreach (['811', '812', '818'] as $acc) {
                if (!Ledger::isMovementAccount($db, $acc)) {
                    throw new UserError("A conta {$acc} tem de existir e ser de movimento para fechar o exercício.");
                }
            }
            $leaf = Reports::leafBalances($db, $year, 1, 12, true, ['apuramento']);
            $date = "{$year}-12-31";
            $created = [];

            $lines = [];
            $net = 0; // débito − crédito acumulado nas contas 6/7
            foreach ($leaf as $acc => $bal) {
                $acc = (string) $acc;
                if (($acc[0] === '6' || $acc[0] === '7') && $bal !== 0) {
                    $lines[] = ['account' => $acc, 'debit_cents' => max(-$bal, 0), 'credit_cents' => max($bal, 0), 'description' => 'Apuramento'];
                    $net += $bal;
                }
            }
            if ($lines) {
                // contrapartida: se gastos > rendimentos ($net > 0) o 811 fica devedor (prejuízo)
                $lines[] = ['account' => '811', 'debit_cents' => max($net, 0), 'credit_cents' => max(-$net, 0), 'description' => 'Resultado antes de impostos'];
                $created[] = Ledger::saveEntry($db, [
                    'date' => $date, 'journal' => 'APU', 'description' => "Apuramento de resultados {$year}",
                    'source' => 'fecho', 'lines' => $lines, 'validate' => true,
                ]);
            }

            $leaf = Reports::leafBalances($db, $year, 1, 12, true);
            $b811 = $leaf['811'] ?? 0;
            $b812 = $leaf['812'] ?? 0;
            if ($b811 !== 0 || $b812 !== 0) {
                $l2 = [];
                if ($b811 !== 0) {
                    $l2[] = ['account' => '811', 'debit_cents' => max(-$b811, 0), 'credit_cents' => max($b811, 0)];
                }
                if ($b812 !== 0) {
                    $l2[] = ['account' => '812', 'debit_cents' => max(-$b812, 0), 'credit_cents' => max($b812, 0)];
                }
                $t = $b811 + $b812;
                $l2[] = ['account' => '818', 'debit_cents' => max($t, 0), 'credit_cents' => max(-$t, 0)];
                $created[] = Ledger::saveEntry($db, [
                    'date' => $date, 'journal' => 'APU', 'description' => "Apuramento do resultado líquido {$year}",
                    'source' => 'fecho', 'lines' => $l2, 'validate' => true,
                ]);
            }
            $db->run("UPDATE fiscal_years SET status = 'fechado', locked_until = end_date WHERE year = ?", [$year]);
            $db->audit('exercicio.fechado', ['year' => $year]);
            return ['year' => $year, 'entries' => array_column($created, 'id')];
        });
    }

    /**
     * Abre o exercício seguinte com um lançamento de abertura (diário ABE) com os saldos das classes 1 a 5.
     * Os saldos da classe 8 (resultado do exercício anterior) passam para 56 — Resultados transitados.
     */
    public static function openNextYear(Db $db, int $year): array
    {
        return $db->transaction(static function () use ($db, $year) {
            $fy = $db->one('SELECT * FROM fiscal_years WHERE year = ?', [$year]);
            if (!$fy || $fy['status'] !== 'fechado') {
                throw new UserError("Feche primeiro o exercício {$year}.");
            }
            $next = $year + 1;
            if (!$db->value('SELECT 1 FROM fiscal_years WHERE year = ?', [$next])) {
                Ledger::createFiscalYear($db, $next);
            }
            if ($db->value("SELECT 1 FROM entries e JOIN journals j ON j.code = e.journal WHERE e.fiscal_year = ? AND j.kind = 'abertura'", [$next])) {
                throw new UserError("O exercício {$next} já tem lançamento de abertura.");
            }
            if (!Ledger::isMovementAccount($db, '56')) {
                throw new UserError('A conta 56 (Resultados transitados) tem de ser de movimento para a abertura.');
            }
            $leaf = Reports::leafBalances($db, $year, 1, 12, true);
            $lines = [];
            $to56 = 0;
            foreach ($leaf as $acc => $bal) {
                $acc = (string) $acc;
                if ($bal === 0) {
                    continue;
                }
                if ($acc[0] === '8') {
                    $to56 += $bal;
                    continue;
                }
                if ($acc[0] >= '1' && $acc[0] <= '5') {
                    if ($acc === '56') {
                        $to56 += $bal;
                        continue;
                    }
                    $lines[] = ['account' => $acc, 'debit_cents' => max($bal, 0), 'credit_cents' => max(-$bal, 0)];
                }
            }
            if ($to56 !== 0) {
                $lines[] = ['account' => '56', 'debit_cents' => max($to56, 0), 'credit_cents' => max(-$to56, 0), 'description' => 'Resultados transitados'];
            }
            if (count($lines) < 2) {
                return ['year' => $next, 'entry' => null];
            }
            $e = Ledger::saveEntry($db, [
                'date' => "{$next}-01-01", 'journal' => 'ABE', 'description' => "Abertura do exercício {$next}",
                'source' => 'abertura', 'lines' => $lines, 'validate' => true,
            ]);
            return ['year' => $next, 'entry' => $e['id']];
        });
    }
}
