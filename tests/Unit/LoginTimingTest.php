<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Security\PasswordHasher;
use SvnManage\Users\HtpasswdFile;

final class LoginTimingTest extends TestCase
{
    public function testUnknownUsersCostOneBcryptAtTheStoredCost(): void
    {
        $hash = PasswordHasher::hash('x');
        self::assertStringStartsWith(sprintf('$2y$%02d$', PasswordHasher::BCRYPT_COST), $hash);
    }

    public function testUnknownAndKnownUsersTakeComparableTime(): void
    {
        $file = HtpasswdFile::parse('alice:' . PasswordHasher::hash('right') . "\n");
        $measure = static function (string $user) use ($file): float {
            $t = hrtime(true);
            for ($i = 0; $i < 4; $i++) {
                $file->verify($user, 'wrong');
            }

            return (hrtime(true) - $t) / 4e6;
        };
        $known = $measure('alice');
        $unknown = $measure('nobody');
        self::assertLessThan(2.0, max($known, $unknown) / max(0.001, min($known, $unknown)), sprintf('known %.1f ms vs unknown %.1f ms', $known, $unknown));
    }
}
