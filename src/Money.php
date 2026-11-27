<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Valores monetários em centavos (inteiros). Nunca usar float para guardar dinheiro.
 */
final class Money
{
    /**
     * Converte texto ou número para centavos. Aceita "1.234,56", "1 234,56", "1234.56", "1,234.56", 1234.5.
     */
    public static function toCents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            return (int) round($value * 100);
        }
        $s = trim((string) $value);
        $s = str_replace(["\u{00A0}", ' ', '$', 'CVE', 'ECV', '€'], '', $s);
        $negative = false;
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
            $negative = true;
            $s = substr($s, 1, -1);
        }
        if (str_starts_with($s, '-')) {
            $negative = true;
            $s = substr($s, 1);
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // o separador decimal é o último que aparece
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            // só vírgulas: decimal se tiver 1-2 casas depois da última vírgula, senão milhares
            $decimals = strlen($s) - $lastComma - 1;
            $s = ($decimals <= 2 && substr_count($s, ',') === 1) ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($lastDot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }
        if (!preg_match('/^\d*(\.\d+)?$/', $s) || $s === '' || $s === '.') {
            throw new UserError("Valor inválido: \"{$value}\". Use, por exemplo, 1 234,56.");
        }
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $frac = str_pad(substr($frac, 0, 3), 3, '0');
        $cents = ((int) ($int === '' ? '0' : $int)) * 100 + intdiv((int) $frac + 5, 10);
        return $negative ? -$cents : $cents;
    }

    /** 123456 -> "1 234,56" (formato cabo-verdiano/português). */
    public static function format(int $cents): string
    {
        $neg = $cents < 0;
        $cents = abs($cents);
        $int = number_format(intdiv($cents, 100), 0, ',', ' ');
        return ($neg ? '-' : '') . $int . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 123456 -> "1234.56" (formato de ficheiros SAF-T). */
    public static function decimal(int $cents): string
    {
        $neg = $cents < 0;
        $cents = abs($cents);
        return ($neg ? '-' : '') . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
