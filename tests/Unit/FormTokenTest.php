<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Http\Request;
use SvnManage\Http\Session;
use SvnManage\Security\FormToken;

final class FormTokenTest extends TestCase
{
    public function testTokenIsBoundToItsCookieAndExpires(): void
    {
        $tokens = new FormToken(str_repeat('k', 64));
        $nonce = FormToken::newNonce();
        $token = $tokens->issue($nonce, 1000);
        self::assertTrue($tokens->verify($token, $nonce, 1100));
        self::assertFalse($tokens->verify($token, FormToken::newNonce(), 1100), 'needs the matching cookie (double submit)');
        self::assertFalse($tokens->verify($token, $nonce, 1000 + 7201), 'expires');
        self::assertFalse($tokens->verify($token . 'x', $nonce, 1100), 'tampered');
        self::assertFalse((new FormToken(str_repeat('z', 64)))->verify($token, $nonce, 1100), 'signed with the server key');
        self::assertFalse($tokens->verify('', '', 1100));
        self::assertFalse($tokens->verify($token, '', 1100));
    }

    public function testAnonymousLoginPageNeedsNoSession(): void
    {
        $get = new Request('GET', '/login', '');
        self::assertFalse(Session::isNeeded($get), 'visiting the login page creates no session file');
        self::assertFalse(Session::isNeeded(new Request('POST', '/login', '')), 'an unsigned login POST gets none either (App starts one only for a valid form token)');
        self::assertTrue(Session::isNeeded(new Request('GET', '/login', '', cookies: ['svnmanage' => 'abc'])));
    }
}
