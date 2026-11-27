<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Gerador de códigos QR (ISO/IEC 18004) sem dependências: modo byte, correção de erros M, versões 1 a 10
 * (até 213 bytes). Usado no código QR da e-Fatura (https://pe.efatura.cv/dfe/view/<IUD>, 76 carateres).
 */
final class Qr
{
    /** [total de codewords, codewords de correção por bloco, blocos do grupo 1, dados por bloco g1, blocos g2, dados g2] */
    private const M = [
        1 => [26, 10, 1, 16, 0, 0], 2 => [44, 16, 1, 28, 0, 0], 3 => [70, 26, 1, 44, 0, 0], 4 => [100, 18, 2, 32, 0, 0],
        5 => [134, 24, 2, 43, 0, 0], 6 => [172, 16, 4, 27, 0, 0], 7 => [196, 18, 4, 31, 0, 0], 8 => [242, 22, 2, 38, 2, 39],
        9 => [292, 22, 3, 36, 2, 37], 10 => [346, 26, 4, 43, 1, 44],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    private int $size;
    /** @var array<int, array<int, bool>> [y][x] */
    private array $mod = [];
    /** @var array<int, array<int, bool>> */
    private array $fn = [];

    /** Matriz de módulos (true = escuro) para o texto. */
    public static function matrix(string $text): array
    {
        return (new self())->encode($text);
    }

    /** SVG do código QR, com margem de 4 módulos. */
    public static function svg(string $text, int $px = 4, string $label = 'Código QR'): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $full = $n + 8;
        $path = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $w = $full * $px;
        return '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" width="' . $w . '" height="' . $w
            . '" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    private function encode(string $text): array
    {
        $len = strlen($text);
        $version = 0;
        foreach (self::M as $v => $t) {
            $dataCw = $t[2] * $t[3] + $t[4] * $t[5];
            $bits = 4 + ($v < 10 ? 8 : 16) + 8 * $len;
            if ($bits <= $dataCw * 8) {
                $version = $v;
                break;
            }
        }
        if (!$version) {
            throw new \InvalidArgumentException('Texto demasiado longo para o código QR.');
        }
        [$total, $ecLen, $b1, $d1, $b2, $d2] = self::M[$version];
        $capacity = ($b1 * $d1 + $b2 * $d2) * 8;

        // fluxo de bits: modo byte (0100), comprimento, dados, terminador, alinhamento e preenchimento
        $bits = [];
        $push = static function (int $val, int $n) use (&$bits): void {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $push(4, 4);
        $push($len, $version < 10 ? 8 : 16);
        for ($i = 0; $i < $len; $i++) {
            $push(ord($text[$i]), 8);
        }
        $push(0, min(4, $capacity - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $push($pad, 8);
        }
        $data = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $data[] = (int) bindec(implode('', $byte));
        }

        // blocos, correção Reed-Solomon e intercalação
        $blocks = [];
        $k = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$count, $dlen]) {
            for ($i = 0; $i < $count; $i++) {
                $blk = array_slice($data, $k, $dlen);
                $k += $dlen;
                $blocks[] = [$blk, self::rsRemainder($blk, $ecLen)];
            }
        }
        $final = [];
        $maxD = max($d1, $d2);
        for ($i = 0; $i < $maxD; $i++) {
            foreach ($blocks as [$blk]) {
                if (isset($blk[$i])) {
                    $final[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($blocks as [, $ec]) {
                $final[] = $ec[$i];
            }
        }
        assert(count($final) === $total);

        $this->size = $version * 4 + 17;
        $this->mod = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->fn = $this->mod;
        $this->drawFunctionPatterns($version);
        $this->drawCodewords($final);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $base = $this->mod;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->mod = $base;
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $p = $this->penalty();
            if ($p < $bestPenalty) {
                $bestPenalty = $p;
                $best = $this->mod;
            }
        }
        return $best;
    }

    // ------------------------------------------------------------------ padrões fixos

    private function set(int $x, int $y, bool $dark): void
    {
        $this->mod[$y][$x] = $dark;
        $this->fn[$y][$x] = true;
    }

    private function drawFunctionPatterns(int $version): void
    {
        $n = $this->size;
        for ($i = 0; $i < $n; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $d = max(abs($dx), abs($dy));
                        $this->set($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGN[$version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $ay) {
            foreach ($pos as $j => $ax) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0); // reserva as áreas (os bits certos são escritos depois da máscara)
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = $version << 12 | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = 0 << 3 | $mask; // nível M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        $n = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $n - 15 + $i, $bit($i));
        }
        $this->set(8, $n - 8, true);
    }

    private function drawCodewords(array $data): void
    {
        $n = $this->size;
        $i = 0;
        $total = count($data) * 8;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $n; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $n - 1 - $vert : $vert;
                    if (!$this->fn[$y][$x] && $i < $total) {
                        $this->mod[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        $n = $this->size;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($this->fn[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->mod[$y][$x] = !$this->mod[$y][$x];
                }
            }
        }
    }

    /** Penalização da norma (regras 1 a 4), para escolher a melhor máscara. */
    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->mod;
        $p = 0;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $lines[] = $m[$i];
            $lines[] = array_column($m, $i);
        }
        $pat1 = '10111010000';
        $pat2 = '00001011101';
        foreach ($lines as $line) {
            $run = 1;
            for ($i = 1; $i <= $n; $i++) {
                if ($i < $n && $line[$i] === $line[$i - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $p += 3 + ($run - 5);
                }
                $run = 1;
            }
            $s = implode('', array_map('intval', $line));
            $p += 40 * (substr_count($s, $pat1) + substr_count($s, $pat2));
        }
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $dark += $m[$y][$x] ? 1 : 0;
                if ($y < $n - 1 && $x < $n - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }
        $p += 10 * intdiv(abs($dark * 20 - $n * $n * 10), $n * $n);
        return $p;
    }

    // ------------------------------------------------------------------ Reed-Solomon em GF(256)

    private static function mul(int $a, int $b): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($b >> $i) & 1) * $a;
        }
        return $z & 0xFF;
    }

    private static function rsRemainder(array $data, int $degree): array
    {
        // divisor: produto de (x - r^i), r = 0x02
        $div = array_fill(0, $degree, 0);
        $div[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $div[$j] = self::mul($div[$j], $root);
                if ($j + 1 < $degree) {
                    $div[$j] ^= $div[$j + 1];
                }
            }
            $root = self::mul($root, 0x02);
        }
        $res = array_fill(0, $degree, 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($res);
            $res[] = 0;
            foreach ($div as $i => $coef) {
                $res[$i] ^= self::mul($coef, $factor);
            }
        }
        return $res;
    }
}
