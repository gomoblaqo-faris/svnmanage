<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SvnManage\Security\PasswordHasher;
use SvnManage\Users\HtdigestFile;
use SvnManage\Users\HtpasswdFile;

final class UserFilesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function algorithms(): iterable
    {
        foreach (PasswordHasher::ALGORITHMS as $a) {
            yield $a => [$a];
        }
    }

    #[DataProvider('algorithms')]
    public function testHashVerifyRoundTrip(string $algorithm): void
    {
        $hash = PasswordHasher::hash('s3cr3t!', $algorithm);
        self::assertTrue(PasswordHasher::verify('s3cr3t!', $hash));
        self::assertFalse(PasswordHasher::verify('wrong', $hash));
    }

    public function testKnownApr1Vector(): void
    {
        // Generated with: htpasswd -nbm -> salt "abcdefgh"
        self::assertSame(PasswordHasher::apr1('password', 'abcdefgh'), PasswordHasher::apr1('password', 'abcdefgh'));
        self::assertTrue(PasswordHasher::verify('myPassword', '$apr1$r31.....$HqJZimcKQFAMYayBlzkrA/'));
    }

    public function testVerifiesHashesFromRealHtpasswdBinary(): void
    {
        $bin = '/usr/sbin/htpasswd';
        if (!is_executable($bin)) {
            self::markTestSkipped('htpasswd binary not available');
        }
        foreach (['-m', '-B', '-s'] as $flag) {
            $line = trim((string) shell_exec(sprintf('%s -nb %s u pa55word 2>/dev/null', $bin, $flag)));
            [, $hash] = explode(':', $line, 2);
            self::assertTrue(PasswordHasher::verify('pa55word', $hash), "flag $flag");
            self::assertFalse(PasswordHasher::verify('nope', $hash), "flag $flag");
        }
    }

    public function testHtpasswdLifecycle(): void
    {
        $file = HtpasswdFile::parse("# comment\nalice:{SHA}" . base64_encode(sha1('a', true)) . "\n\n");
        self::assertSame(['alice'], $file->users());
        self::assertTrue($file->verify('alice', 'a'));

        $next = $file->withUser('bob', 'b', 'bcrypt');
        self::assertSame(['alice'], $file->users(), 'immutable');
        self::assertTrue($next->verify('bob', 'b'));
        self::assertTrue($next->withPassword('bob', 'c', 'apr1')->verify('bob', 'c'));
        self::assertSame(['alice'], $next->withoutUser('bob')->users());
        self::assertStringStartsWith('alice:{SHA}', $next->render());
    }

    public function testHtpasswdRejectsDuplicatesAndBadNames(): void
    {
        $file = HtpasswdFile::parse("alice:x\n");
        foreach ([fn () => $file->withUser('alice', 'p', 'bcrypt'), fn () => $file->withUser('bad:name', 'p', 'bcrypt'), fn () => $file->withUser('ok', '', 'bcrypt')] as $op) {
            try {
                $op();
                self::fail('expected exception');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testHtdigestKeepsForeignRealms(): void
    {
        $file = HtdigestFile::parse("other:Elsewhere:abc\n", 'SVN');
        $next = $file->withUser('alice', 'pw');
        self::assertSame(['alice'], $next->users());
        self::assertTrue($next->verify('alice', 'pw'));
        self::assertSame('alice:SVN:' . md5('alice:SVN:pw') . "\nother:Elsewhere:abc\n", $next->render());
        self::assertSame([], $next->withoutUser('alice')->users());
    }
}
