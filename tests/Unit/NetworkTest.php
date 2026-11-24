<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Http\Request;
use SvnManage\Security\Network;

final class NetworkTest extends TestCase
{
    public function testCidrMatching(): void
    {
        self::assertTrue(Network::matches('10.1.2.3', '10.0.0.0/8'));
        self::assertFalse(Network::matches('11.1.2.3', '10.0.0.0/8'));
        self::assertTrue(Network::matches('192.168.1.7', '192.168.1.7'));
        self::assertTrue(Network::matches('192.168.1.7', '192.168.1.0/24'));
        self::assertFalse(Network::matches('192.168.2.7', '192.168.1.0/24'));
        self::assertTrue(Network::matches('1.2.3.4', '0.0.0.0/0'));
        self::assertTrue(Network::matches('2001:db8::1', '2001:db8::/32'));
        self::assertFalse(Network::matches('2001:db9::1', '2001:db8::/32'));
        self::assertTrue(Network::matches('::1', '::1/128'));
        self::assertFalse(Network::matches('10.0.0.1', '2001:db8::/32'), 'families never match');
        self::assertFalse(Network::matches('garbage', '10.0.0.0/8'));
        self::assertFalse(Network::matches('10.0.0.1', '10.0.0.0/33'));
        self::assertTrue(Network::matchesAny('10.0.0.1', ['192.168.0.0/16', '10.0.0.0/8']));
        self::assertFalse(Network::matchesAny('10.0.0.1', []));
    }

    public function testParseListValidates(): void
    {
        self::assertSame(['10.0.0.0/8', '::1'], Network::parseList(" 10.0.0.0/8 ,\n::1 "));
        self::assertSame([], Network::parseList(''));
        $this->expectException(\InvalidArgumentException::class);
        Network::parseList('10.0.0.0/8, not-an-ip');
    }

    public function testForwardedHeadersOnlyFromTrustedProxies(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $direct = new Request('GET', '/', '', [], [], [], $server);
        self::assertSame('203.0.113.9', $direct->ip(), 'spoofed XFF from a client is ignored');
        self::assertFalse($direct->isSecure(), 'spoofed X-Forwarded-Proto from a client is ignored');

        $proxied = $direct->withTrustedProxies(['203.0.113.0/24']);
        self::assertSame('6.6.6.6', $proxied->ip());
        self::assertTrue($proxied->isSecure());

        $chain = (new Request('GET', '/', '', [], [], [], ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 7.7.7.7, 10.0.0.1']))
            ->withTrustedProxies(['10.0.0.0/8']);
        self::assertSame('7.7.7.7', $chain->ip(), 'rightmost untrusted hop, so clients cannot prepend fake addresses');

        $junk = (new Request('GET', '/', '', [], [], [], ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'nonsense']))
            ->withTrustedProxies(['10.0.0.0/8']);
        self::assertSame('10.0.0.2', $junk->ip());
    }

    public function testIpv4MappedAndZonedAddressesAreNormalised(): void
    {
        self::assertTrue(Network::matches('::ffff:203.0.113.5', '203.0.113.0/24'), 'dual-stack sockets report IPv4 clients like this');
        self::assertTrue(Network::matches('203.0.113.5', '::ffff:203.0.113.0/120'));
        self::assertTrue(Network::matches('fe80::1%eth0', 'fe80::/10'));
        self::assertSame('203.0.113.5', Network::normalise('::ffff:203.0.113.5'));
    }

    public function testTrustedProxyRangesMustBeNarrow(): void
    {
        foreach (['0.0.0.0/0', '10.0.0.0/7', '::/0', '2001::/31'] as $wide) {
            try {
                Network::parseList($wide, true);
                self::fail($wide . ' should be rejected');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(['10.0.0.0/8', '2001:db8::/32'], Network::parseList('10.0.0.0/8, 2001:db8::/32', true));
        self::assertSame(['0.0.0.0/0'], Network::parseList('0.0.0.0/0'), 'the allow-list may be wide');
    }

    public function testMappedRangesCannotWidenATrustedProxy(): void
    {
        foreach (['::ffff:0.0.0.0/96', '::ffff:10.0.0.0/100', '::/0'] as $wide) {
            try {
                Network::parseList($wide, true);
                self::fail("$wide was accepted");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(['::ffff:10.0.0.5/128'], Network::parseList('::ffff:10.0.0.5/128', true));
    }
}
