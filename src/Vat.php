<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Apuramento do IVA por período, a partir das contas 2432 (dedutível), 2433 (liquidado) e 2434 (regularizações),
 * com o detalhe das bases tributáveis por código de IVA e por terceiro.
 */
final class Vat
{
    public static function settlement(Db $db, int $year, int $from, int $to): array
    {
        $sum = static fn(string $acc) => (int) $db->value(
            "SELECT COALESCE(SUM(debit_cents - credit_cents), 0) FROM v_movements
             WHERE fiscal_year = ? AND account LIKE ? AND month BETWEEN ? AND ? AND journal_kind = 'normal' AND COALESCE(entry_description,'') NOT LIKE 'Apuramento de IVA%'",
            [$year, $acc . '%', $from, $to]
        );
        $liquidado = -$sum('2433');
        $dedutivel = $sum('2432');
        $regularizacoes = $sum('2434'); // devedor = a favor da empresa
        $resultado = $liquidado - $dedutivel - $regularizacoes;

        $byCode = $db->all(
            "SELECT m.vat_code, v.name, v.rate_bp, v.kind, COUNT(*) AS lines,
                    SUM(COALESCE(m.vat_base_cents, 0)) AS base,
                    SUM(m.credit_cents - m.debit_cents) AS net_credit
             FROM v_movements m JOIN vat_codes v ON v.code = m.vat_code
             WHERE m.fiscal_year = ? AND m.month BETWEEN ? AND ? AND m.vat_code IS NOT NULL
             GROUP BY m.vat_code ORDER BY m.vat_code",
            [$year, $from, $to]
        );
        foreach ($byCode as &$r) {
            $r['vat'] = $r['kind'] === 'dedutivel' ? -(int) $r['net_credit'] : (int) $r['net_credit'];
            unset($r['net_credit']);
        }
        unset($r);

        // Mapa por terceiro (NIF): base e IVA por lançamento com código de IVA, atribuídos ao terceiro do lançamento.
        $byParty = $db->all(
            "SELECT t.nif, t.name, v.kind,
                    SUM(COALESCE(m.vat_base_cents, 0)) AS base,
                    SUM(CASE WHEN v.kind = 'dedutivel' THEN m.debit_cents - m.credit_cents ELSE m.credit_cents - m.debit_cents END) AS vat
             FROM v_movements m
             JOIN vat_codes v ON v.code = m.vat_code
             JOIN (SELECT entry_id, MIN(third_party) AS tp FROM entry_lines WHERE third_party IS NOT NULL GROUP BY entry_id) x ON x.entry_id = m.entry_id
             JOIN third_parties t ON t.id = x.tp
             WHERE m.fiscal_year = ? AND m.month BETWEEN ? AND ? AND v.kind <> 'isento'
             GROUP BY t.id, v.kind ORDER BY v.kind, base DESC",
            [$year, $from, $to]
        );

        return [
            'year' => $year, 'from' => $from, 'to' => $to,
            'liquidado' => $liquidado,
            'dedutivel' => $dedutivel,
            'regularizacoes' => $regularizacoes,
            'resultado' => $resultado,
            'situacao' => $resultado > 0 ? 'a_pagar' : ($resultado < 0 ? 'a_recuperar' : 'nulo'),
            'by_code' => $byCode,
            'by_party' => $byParty,
        ];
    }

    /**
     * Gera o lançamento de apuramento: salda 2432/2433/2434 por contrapartida de 2436 (a pagar) ou 2437 (a recuperar).
     */
    public static function postSettlement(Db $db, int $year, int $from, int $to): array
    {
        $s = self::settlement($db, $year, $from, $to);
        if ($s['liquidado'] === 0 && $s['dedutivel'] === 0 && $s['regularizacoes'] === 0) {
            throw new UserError('Não há IVA a apurar neste período.');
        }
        foreach (['2432', '2433', '2434', '2436', '2437'] as $acc) {
            if (!Ledger::isMovementAccount($db, $acc)) {
                throw new UserError("A conta {$acc} tem de existir e ser de movimento para gerar o apuramento.");
            }
        }
        $lines = [];
        if ($s['liquidado'] !== 0) {
            $lines[] = ['account' => '2433', 'debit_cents' => max($s['liquidado'], 0), 'credit_cents' => max(-$s['liquidado'], 0)];
        }
        if ($s['dedutivel'] !== 0) {
            $lines[] = ['account' => '2432', 'debit_cents' => max(-$s['dedutivel'], 0), 'credit_cents' => max($s['dedutivel'], 0)];
        }
        if ($s['regularizacoes'] !== 0) {
            $lines[] = ['account' => '2434', 'debit_cents' => max(-$s['regularizacoes'], 0), 'credit_cents' => max($s['regularizacoes'], 0)];
        }
        if ($s['resultado'] > 0) {
            $lines[] = ['account' => '2436', 'debit_cents' => 0, 'credit_cents' => $s['resultado']];
        } elseif ($s['resultado'] < 0) {
            $lines[] = ['account' => '2437', 'debit_cents' => -$s['resultado'], 'credit_cents' => 0];
        }
        $last = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $to)));
        return Ledger::saveEntry($db, [
            'date' => sprintf('%04d-%02d-%02d', $year, $to, $last),
            'journal' => 'DIV',
            'description' => sprintf('Apuramento de IVA %02d a %02d/%d', $from, $to, $year),
            'source' => 'apuramento_iva',
            'lines' => $lines,
            'validate' => true,
        ]);
    }
}
