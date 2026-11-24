<?php

declare(strict_types=1);

namespace SvnManage\Security;

/**
 * Failed-attempt throttle: N failures within a window lock the key.
 *
 * Each key has its own small file (sha256, sharded by the first two hex digits), read
 * and written under flock. So concurrent requests never see a half-written state, one
 * attacker flooding new keys cannot evict another key's lock, and no request ever has
 * to decode a file whose size an attacker controls. Expired files are swept occasionally.
 */
final class RateLimiter
{
    private const GC_PROBABILITY = 100; // 1 in N hits sweeps expired entries

    public function __construct(
        private readonly string $dir,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
    ) {
    }

    /** Seconds until the key may try again (0 = allowed). */
    public function retryAfter(string $key, ?int $now = null): int
    {
        $now ??= time();
        $entry = $this->withFile($key, false, static fn (?array $e): ?array => $e);
        if ($entry === null || $entry['count'] < $this->maxAttempts) {
            return 0;
        }

        return max(0, $entry['first'] + $this->windowSeconds - $now);
    }

    /**
     * Reserves one attempt atomically: returns 0 and counts the attempt, or returns the
     * seconds to wait without counting. Reserving *before* the credential check means
     * parallel requests cannot all slip through while the count is still low.
     */
    public function attempt(string $key, ?int $now = null): int
    {
        $now ??= time();
        $wait = 0;
        $this->withFile($key, true, function (?array $entry) use ($now, &$wait): array {
            if ($entry === null || $entry['first'] + $this->windowSeconds < $now) {
                $entry = ['count' => 0, 'first' => $now];
            }
            if ($entry['count'] >= $this->maxAttempts) {
                $wait = max(1, $entry['first'] + $this->windowSeconds - $now);

                return $entry;
            }

            return ['count' => $entry['count'] + 1, 'first' => $entry['first']];
        });
        if ($wait === 0 && random_int(1, self::GC_PROBABILITY) === 1) {
            $this->collectGarbage($now);
        }

        return $wait;
    }

    /** Gives back an attempt reserved with attempt() (the action turned out legitimate). */
    public function refund(string $key): void
    {
        if (!is_file($this->file($key))) {
            return;
        }
        $this->withFile($key, true, static fn (?array $e): ?array => $e === null ? null
            : ['count' => max(0, $e['count'] - 1), 'first' => $e['first']]);
    }

    public function hit(string $key, ?int $now = null): void
    {
        $now ??= time();
        $this->withFile($key, true, function (?array $entry) use ($now): array {
            if ($entry === null || $entry['first'] + $this->windowSeconds < $now) {
                $entry = ['count' => 0, 'first' => $now];
            }

            return ['count' => $entry['count'] + 1, 'first' => $entry['first']];
        });
        if (random_int(1, self::GC_PROBABILITY) === 1) {
            $this->collectGarbage($now);
        }
    }

    public function clear(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Deletes expired entries. Without $shard one random shard (1/256 of the entries) is
     * swept, so the cost of a sweep stays small however many keys an attacker creates;
     * '*' sweeps everything (maintenance and tests).
     */
    public function collectGarbage(?int $now = null, ?string $shard = null): void
    {
        $now ??= time();
        $shard ??= bin2hex(chr(random_int(0, 255)));
        foreach (glob($this->dir . '/' . $shard . '/*.json') ?: [] as $file) {
            $entry = self::decode((string) @file_get_contents($file));
            if ($entry === null || $entry['first'] + $this->windowSeconds < $now) {
                @unlink($file);
            }
        }
    }

    private function file(string $key): string
    {
        $hash = hash('sha256', strtolower($key));

        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.json';
    }

    /**
     * Runs $fn on the key's entry under a lock. Writes the result back when $write.
     *
     * @param callable(?array{count: int, first: int}): ?array{count: int, first: int} $fn
     * @return ?array{count: int, first: int}
     */
    private function withFile(string $key, bool $write, callable $fn): ?array
    {
        $file = $this->file($key);
        if (!$write && !is_file($file)) {
            return $fn(null);
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the rate limit folder.');
        }
        $handle = @fopen($file, $write ? 'c+' : 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the rate limit file.');
        }
        try {
            flock($handle, $write ? LOCK_EX : LOCK_SH);
            $entry = self::decode((string) stream_get_contents($handle));
            $next = $fn($entry);
            if ($write && $next === null) {
                ftruncate($handle, 0);
            } elseif ($write) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, (string) json_encode($next));
                fflush($handle);
            }

            return $next;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return ?array{count: int, first: int} */
    private static function decode(string $json): ?array
    {
        $data = json_decode($json, true);

        return is_array($data) && is_int($data['count'] ?? null) && is_int($data['first'] ?? null)
            ? ['count' => $data['count'], 'first' => $data['first']]
            : null;
    }
}
