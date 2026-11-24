<?php

declare(strict_types=1);

namespace SvnManage\Security;

use InvalidArgumentException;

/** IPv4/IPv6 address and CIDR matching, used for trusted proxies and the IP allow-list. */
final class Network
{
    /** Strips IPv6 zone ids and unmaps IPv4-mapped IPv6 ("::ffff:1.2.3.4" -> "1.2.3.4"). */
    public static function normalise(string $ip): string
    {
        $ip = (string) preg_replace('/%.*$/', '', trim($ip));
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }

        return $ip;
    }

    public static function matches(string $ip, string $cidr): bool
    {
        [$network, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        if ($bits !== null && ctype_digit($bits) && str_starts_with(strtolower($network), '::ffff:') && str_contains($network, '.')) {
            $bits = (string) max(0, (int) $bits - 96); // "::ffff:a.b.c.d/120" is the IPv4 range a.b.c.d/24
        }
        $network = self::normalise($network);
        $ipBin = @inet_pton(self::normalise($ip));
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        if ($bits === null) {
            $bits = $max;
        } elseif (!ctype_digit($bits) || (int) $bits > $max) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** @param list<string> $cidrs */
    public static function matchesAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::matches($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function isValid(string $cidr): bool
    {
        [$network, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $bin = @inet_pton(self::normalise($network));
        if ($bin !== false && $bits !== null && strlen($bin) === 4 && str_contains($network, ':') && ctype_digit($bits)) {
            $bits = (string) max(0, (int) $bits - 96);
        }
        if ($bin === false) {
            return false;
        }

        return $bits === null || (ctype_digit($bits) && (int) $bits <= strlen($bin) * 8);
    }

    /**
     * Parses a comma/whitespace separated list of addresses or CIDR ranges.
     *
     * @param bool $narrow refuse ranges wider than /8 (IPv4) or /32 (IPv6): for trusted
     *                    proxies, where "everyone" would let any client forge its address
     * @return list<string>
     */
    /**
     * The identity used for throttling: an IPv4 address, or the /64 network of an IPv6
     * address (one customer usually holds a whole /64, i.e. 2^64 addresses).
     */
    public static function throttleKey(string $ip): string
    {
        $ip = self::normalise($ip);
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        return bin2hex(substr($packed, 0, 8)) . '::/64';
    }

    public static function parseList(string $list, bool $narrow = false): array
    {
        $items = array_values(array_filter(preg_split('/[\s,]+/', trim($list)) ?: [], static fn (string $s): bool => $s !== ''));
        foreach ($items as $item) {
            if (!self::isValid($item)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid IP address or CIDR range.', $item));
            }
            if ($narrow && str_contains($item, '/')) {
                [$network, $bits] = explode('/', $item, 2);
                $min = str_contains($network, ':') ? 32 : 8;
                // An IPv4-mapped range (::ffff:a.b.c.d/n) is matched as IPv4 /(n-96).
                if (str_starts_with(strtolower($network), '::ffff:')) {
                    [$bits, $min] = [(int) $bits - 96, 8];
                }
                if ((int) $bits < $min) {
                    throw new InvalidArgumentException(sprintf('"%s" is too wide for a trusted proxy (at most /%d).', $item, $min));
                }
            }
        }

        return $items;
    }
}
