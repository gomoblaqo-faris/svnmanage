<?php

declare(strict_types=1);

namespace SvnManage\Svn;

use RuntimeException;

final class SvnException extends RuntimeException
{
    public static function fromResult(string $what, ProcessResult $result): self
    {
        $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);
        $detail = preg_replace('/^svn(admin|look)?: (E\d+: )?/m', '', $detail) ?? $detail;

        return new self(sprintf('%s failed (exit %d): %s', $what, $result->exitCode, $detail));
    }
}
