<?php

declare(strict_types=1);

namespace SvnManage\Security;

use InvalidArgumentException;

/**
 * Minimal QR code encoder (ISO/IEC 18004): byte mode, error correction level M,
 * versions 1-10 (up to 213 bytes). Enough for otpauth:// provisioning URIs.
 */
final class QrCode
{
    /** version => [ec codewords per block, [[blocks, data codewords], ...]] for level M */
    private const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var list<list<bool>> */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $function = [];
    private int $size;

    private function __construct(private readonly int $version)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->function = array_fill(0, $this->size, $row);
    }

    /** @return list<list<bool>> rows of dark (true) / light (false) modules */
    public static function encode(string $text): array
    {
        $version = self::chooseVersion(strlen($text));
        $qr = new self($version);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords(self::interleave($version, self::dataCodewords($version, $text)));
        $qr->applyBestMask();

        return $qr->modules;
    }

    /** Renders the symbol as a crisp SVG (one path, 4-module quiet zone). */
    public static function svg(string $text, string $label): string
    {
        $m = self::encode($text);
        $n = count($m);
        $d = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $box = $n + 8;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $box . ' ' . $box . '" role="img" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES) . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/>'
            . '<path fill="#0f172a" d="' . $d . '"/></svg>';
    }

    /** Reed-Solomon error correction codewords over GF(256), polynomial 0x11D. */
    public static function reedSolomon(array $data, int $ecLength): array
    {
        $generator = [1];
        $root = 1;
        for ($i = 0; $i < $ecLength; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::gfMultiply($coef, $root);
            }
            $generator = $next;
            $root = self::gfMultiply($root, 2);
        }
        $result = array_fill(0, $ecLength, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $ecLength; $i++) {
                $result[$i] ^= self::gfMultiply($generator[$i + 1], $factor);
            }
        }

        return $result;
    }

    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    private static function capacity(int $version): int
    {
        $total = 0;
        foreach (self::BLOCKS[$version][1] as [$count, $data]) {
            $total += $count * $data;
        }

        return $total;
    }

    private static function chooseVersion(int $length): int
    {
        foreach (array_keys(self::BLOCKS) as $version) {
            $countBits = $version < 10 ? 8 : 16;
            if (4 + $countBits + 8 * $length <= self::capacity($version) * 8) {
                return $version;
            }
        }
        throw new InvalidArgumentException('Text is too long for a QR code.');
    }

    /** @return list<int> */
    private static function dataCodewords(int $version, string $text): array
    {
        $capacityBits = self::capacity($version) * 8;
        $bits = '0100' . str_pad(decbin(strlen($text)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $bytes = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($bytes) < $capacityBits / 8; $pad ^= 0xEC ^ 0x11) {
            $bytes[] = $pad;
        }

        return $bytes;
    }

    /** Splits data into blocks, adds error correction and interleaves column-wise. */
    private static function interleave(int $version, array $data): array
    {
        [$ecLength, $groups] = self::BLOCKS[$version];
        $blocks = [];
        $offset = 0;
        foreach ($groups as [$count, $size]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($data, $offset, $size);
                $offset += $size;
                $blocks[] = [$chunk, self::reedSolomon($chunk, $ecLength)];
            }
        }
        $out = [];
        $longest = max(array_map(static fn (array $b): int => count($b[0]), $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as [$chunk]) {
                if (isset($chunk[$i])) {
                    $out[] = $chunk[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLength; $i++) {
            foreach ($blocks as [, $ec]) {
                $out[] = $ec[$i];
            }
        }

        return $out;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->function[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $dist = max(abs($dx), abs($dy));
                        $this->set($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGNMENT[$this->version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $cx) {
            foreach ($pos as $j => $cy) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0); // reserve the format areas; redrawn once the mask is known
        $this->drawVersionBits();
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0 << 3) | $mask; // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
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
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }
        $this->set(8, $this->size - 8, true); // the dark module
    }

    private function drawVersionBits(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->set($a, $b, $dark);
            $this->set($b, $a, $dark);
        }
    }

    /** Places the codeword bits in the two-column zigzag, skipping function modules. */
    private function drawCodewords(array $codewords): void
    {
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->function[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private static function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
        };
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if (!$this->function[$y][$x] && self::maskBit($mask, $x, $y)) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function applyBestMask(): void
    {
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) {
                $best = $mask;
                $bestPenalty = $penalty;
            }
            $this->applyMask($mask); // XOR again to undo
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    /** Penalty rules 1 (runs), 2 (2x2 blocks) and 4 (dark balance); only used to pick a mask. */
    private function penalty(): int
    {
        $score = 0;
        $dark = 0;
        for ($a = 0; $a < $this->size; $a++) {
            $runRow = 1;
            $runCol = 1;
            for ($b = 0; $b < $this->size; $b++) {
                $dark += $this->modules[$a][$b] ? 1 : 0;
                if ($b === 0) {
                    continue;
                }
                $runRow = $this->modules[$a][$b] === $this->modules[$a][$b - 1] ? $runRow + 1 : 1;
                $runCol = $this->modules[$b][$a] === $this->modules[$b - 1][$a] ? $runCol + 1 : 1;
                $score += ($runRow === 5 ? 3 : ($runRow > 5 ? 1 : 0)) + ($runCol === 5 ? 3 : ($runCol > 5 ? 1 : 0));
                if ($a > 0) {
                    $c = $this->modules[$a][$b];
                    if ($c === $this->modules[$a][$b - 1] && $c === $this->modules[$a - 1][$b] && $c === $this->modules[$a - 1][$b - 1]) {
                        $score += 3;
                    }
                }
            }
        }
        $percent = intdiv($dark * 100, $this->size * $this->size);

        return $score + intdiv(abs($percent - 50), 5) * 10;
    }
}
