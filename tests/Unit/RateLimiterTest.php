<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Security\RateLimiter;

final class RateLimiterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/svnm-rl-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testLocksAfterMaxAttemptsAndExpires(): void
    {
        $rl = new RateLimiter($this->dir, 3, 60);
        $t = 1_000_000;
        $rl->hit('a', $t);
        $rl->hit('a', $t);
        self::assertSame(0, $rl->retryAfter('a', $t));
        $rl->hit('a', $t);
        self::assertSame(60, $rl->retryAfter('a', $t));
        self::assertSame(0, $rl->retryAfter('a', $t + 61));
        self::assertSame(0, $rl->retryAfter('b', $t), 'keys are independent');
        $rl->clear('a');
        self::assertSame(0, $rl->retryAfter('a', $t));
    }

    public function testFloodOfOtherKeysCannotEvictALock(): void
    {
        $rl = new RateLimiter($this->dir, 2, 900);
        $t = time();
        $rl->hit('admin|1.2.3.4', $t);
        $rl->hit('admin|1.2.3.4', $t);
        for ($i = 0; $i < 6000; $i++) {
            $rl->hit('junk' . $i, $t);
        }
        self::assertGreaterThan(0, $rl->retryAfter('admin|1.2.3.4', $t));
    }

    public function testExpiredEntriesAreGarbageCollected(): void
    {
        $rl = new RateLimiter($this->dir, 2, 60);
        for ($i = 0; $i < 50; $i++) {
            $rl->hit('old' . $i, 1_000_000);
        }
        $rl->collectGarbage(1_000_000 + 61, '*');
        self::assertSame([], glob($this->dir . '/*/*') ?: []);
    }

    public function testCorruptFileFailsClosedForThatKeyOnly(): void
    {
        $rl = new RateLimiter($this->dir, 2, 60);
        $rl->hit('k', time());
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            file_put_contents($f, '{garbage');
        }
        self::assertSame(0, $rl->retryAfter('other'));
        $rl->hit('k');
        $rl->hit('k');
        self::assertGreaterThan(0, $rl->retryAfter('k'), 'a corrupt entry restarts counting instead of crashing');
    }
}
