<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Security\QrCode;

final class QrCodeTest extends TestCase
{
    public function testReedSolomonMatchesTheStandardExample(): void
    {
        // "HELLO WORLD" as 1-M data codewords, with their published error correction codewords.
        $data = [32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17];
        self::assertSame([196, 35, 39, 119, 235, 215, 231, 226, 93, 23], QrCode::reedSolomon($data, 10));
    }

    public function testSymbolSizeAndFinderPatterns(): void
    {
        $uri = 'otpauth://totp/svnmanage:administrator?secret=' . str_repeat('A', 32) . '&issuer=svnmanage&algorithm=SHA1&digits=6&period=30';
        $m = QrCode::encode($uri);
        $n = count($m);
        self::assertSame(0, ($n - 17) % 4);
        self::assertGreaterThanOrEqual(37, $n, 'a URI of this length needs version 5 or more');
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x, $y]) {
            self::assertTrue($m[$y][$x] && $m[$y + 6][$x + 6], 'finder corners are dark');
            self::assertFalse($m[$y + 1][$x + 1], 'finder ring is light');
            self::assertTrue($m[$y + 3][$x + 3], 'finder centre is dark');
        }
        self::assertTrue($m[$n - 8][8], 'dark module');
    }

    public function testSvgIsSafe(): void
    {
        $svg = QrCode::svg('hello', 'QR "code" <x>');
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('aria-label="QR &quot;code&quot; &lt;x&gt;"', $svg);
        self::assertStringNotContainsString('<script', $svg);
    }

    public function testTooLongTextIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::encode(str_repeat('x', 300));
    }
}
