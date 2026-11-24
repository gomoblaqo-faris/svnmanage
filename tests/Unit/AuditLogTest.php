<?php

declare(strict_types=1);

namespace SvnManage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SvnManage\Audit\AuditLog;

final class AuditLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/svnm-audit-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testFieldsAreTruncated(): void
    {
        $log = new AuditLog($this->dir . '/audit.log');
        $log->record(str_repeat('u', 5000), '1.2.3.4', 'login.failed', str_repeat('t', 1_000_000), ['member' => str_repeat('m', 50_000)]);
        $size = filesize($this->dir . '/audit.log');
        self::assertLessThan(AuditLog::MAX_LINE_BYTES + 1, $size);
        $row = $log->recent(1)[0];
        self::assertStringEndsWith('…', $row['target']);
    }

    public function testRotationKeepsSeveralGenerations(): void
    {
        $log = new AuditLog($this->dir . '/audit.log', 2000);
        for ($i = 0; $i < 200; $i++) {
            $log->record('admin', '127.0.0.1', 'user.create', 'user' . $i);
        }
        self::assertFileExists($this->dir . '/audit.log.1');
        self::assertFileExists($this->dir . '/audit.log.' . AuditLog::GENERATIONS);
        self::assertFileDoesNotExist($this->dir . '/audit.log.' . (AuditLog::GENERATIONS + 1));
        self::assertSame('user199', $log->recent(1)[0]['target']);
    }
}
