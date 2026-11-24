<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Security\Totp;

final class TotpTest extends TestCase
{
    /** RFC 6238 appendix B (SHA-1), truncated to the 6 digits authenticator apps use. */
    public function testRfc6238Vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            self::assertSame($code, Totp::code($secret, intdiv($time, 30)), "t=$time");
        }
    }

    public function testBase32RoundTrip(): void
    {
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32Encode('12345678901234567890'));
        self::assertSame('12345678901234567890', Totp::base32Decode('gezd gnbv gy3t qojq gezd gnbv gy3t qojq'));
        $secret = Totp::generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertNotSame($secret, Totp::generateSecret());
    }

    public function testVerifyAllowsOneStepOfDriftAndRejectsReplay(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $step = intdiv($now, 30);
        self::assertSame($step, Totp::verify($secret, Totp::code($secret, $step), $now, null));
        self::assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), $now, null), 'previous step (clock drift)');
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $now, null), 'next step');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), $now, null), 'too old');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step), $now, $step), 'replay of an already used code');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step - 1), $now, $step), 'older than the last used code');
        self::assertNull(Totp::verify($secret, 'abcdef', $now, null));
        self::assertNull(Totp::verify($secret, '', $now, null));
        self::assertSame($step, Totp::verify($secret, ' ' . substr(Totp::code($secret, $step), 0, 3) . ' ' . substr(Totp::code($secret, $step), 3), $now, null), 'spaces are ignored');
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'alice@corp', 'svn admin');
        self::assertSame('otpauth://totp/svn%20admin:alice%40corp?secret=JBSWY3DPEHPK3PXP&issuer=svn%20admin&algorithm=SHA1&digits=6&period=30', $uri);
    }
}
