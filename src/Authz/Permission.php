<?php

declare(strict_types=1);

namespace SvnManage\Authz;

/** A Subversion path permission as stored in the authz file. */
enum Permission: string
{
    case None = '';
    case Read = 'r';
    case ReadWrite = 'rw';

    public static function fromAuthz(string $value): self
    {
        $normalized = strtolower(str_replace(' ', '', $value));

        return match ($normalized) {
            'rw', 'wr' => self::ReadWrite,
            'r' => self::Read,
            default => self::None,
        };
    }

    /** Accepts form values: none / r / rw (and the legacy iF.SVNAdmin names). */
    public static function fromInput(?string $value): self
    {
        return match ($value) {
            'rw', 'Read-Write' => self::ReadWrite,
            'r', 'Read' => self::Read,
            'none', '', 'No-Access', null => self::None,
            default => throw new \InvalidArgumentException('Unknown permission: ' . $value),
        };
    }

    public function inputValue(): string
    {
        return $this === self::None ? 'none' : $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::ReadWrite => 'Read-Write',
            self::Read => 'Read-only',
            self::None => 'No access',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::ReadWrite => 2,
            self::Read => 1,
            self::None => 0,
        };
    }
}
