<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Http\Router;
use SvnManage\Security\SessionRevocations;

final class SessionSafetyTest extends TestCase
{
    public function testRevocationHoldsWithinTheSameSecond(): void
    {
        $file = sys_get_temp_dir() . '/svnm-rev-' . bin2hex(random_bytes(4)) . '.json';
        try {
            $revocations = new SessionRevocations($file);
            $revocations->revoke('alice', 1000.7);
            self::assertTrue($revocations->isRevoked('alice', 1000.2), 'a session that began earlier in the same second is revoked');
            self::assertTrue($revocations->isRevoked('alice', 999.0));
            self::assertFalse($revocations->isRevoked('alice', 1000.9), 'a session that began after the revocation survives');
            self::assertFalse($revocations->isRevoked('bob', 1.0));
        } finally {
            @unlink($file);
        }
    }

    public function testRouteParametersAreNotDecodedASecondTime(): void
    {
        $router = (new Router())->get('/users/{name}', ['X', 'show']);
        // The front controller has already decoded the URL once: "%2F" here was "%252F" on the wire.
        self::assertSame(['name' => 'a%2Fb'], $router->match('GET', '/users/a%2Fb')['params']);
    }
}
