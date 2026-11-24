<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Security\Network;
use SvnManage\Security\RateLimiter;
use SvnManage\Security\Throttle;

final class ThrottleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/svnm-th-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function throttle(): Throttle
    {
        return new Throttle($this->dir, 'login', perKey: 3, perAddress: 10, perAccount: 6, window: 60);
    }

    public function testAttemptIsReservedBeforeTheCheck(): void
    {
        $rl = new RateLimiter($this->dir, 2, 60);
        self::assertSame(0, $rl->attempt('k', 100));
        self::assertSame(0, $rl->attempt('k', 100));
        self::assertSame(60, $rl->attempt('k', 100), 'third parallel attempt is refused before any check runs');
        $rl->refund('k');
        self::assertSame(0, $rl->attempt('k', 100), 'a refund gives the attempt back');
    }

    public function testSuccessClearsTheKeyAndRefundsSharedBudgets(): void
    {
        $t = $this->throttle();
        for ($i = 0; $i < 9; $i++) {
            self::assertSame(0, $t->reserve('alice', '198.51.100.7'));
            $t->succeeded('alice', '198.51.100.7');
        }
        self::assertSame(0, $t->reserve('alice', '198.51.100.7'), 'successful logins never lock an office NAT');
    }

    public function testRotatingIpv6WithinOneSlash64SharesOneBudget(): void
    {
        $t = $this->throttle();
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(0, $t->reserve('alice', "2001:db8:1:2::$i"));
        }
        self::assertGreaterThan(0, $t->reserve('alice', '2001:db8:1:2:ffff::9'));
        self::assertSame(Network::throttleKey('2001:db8:1:2::1'), Network::throttleKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertNotSame(Network::throttleKey('2001:db8:1:2::1'), Network::throttleKey('2001:db8:1:3::1'));
        self::assertSame('203.0.113.9', Network::throttleKey('203.0.113.9'));
    }

    public function testAccountCeilingHoldsAgainstManyAddresses(): void
    {
        $t = $this->throttle();
        for ($i = 1; $i <= 6; $i++) {
            self::assertSame(0, $t->reserve('alice', "203.0.113.$i"));
        }
        self::assertGreaterThan(0, $t->reserve('alice', '203.0.113.99'), 'a botnet cannot guess one password without limit');
        self::assertSame(0, $t->reserve('bob', '203.0.113.99'), 'other accounts are unaffected');
    }

    public function testScopesAndNamesCannotCollide(): void
    {
        $login = $this->throttle();
        $setup = new Throttle($this->dir, 'setup', perKey: 3, perAddress: 10, perAccount: 6, window: 60);
        for ($i = 0; $i < 3; $i++) {
            $login->reserve('ip', '198.51.100.1');
        }
        self::assertSame(0, $setup->reserve('ip', '198.51.100.2'));
    }
}
