<?php

declare(strict_types=1);

namespace SvnManage\Security;

/** A file or folder svnmanage manages. Secret ones hold password hashes or keys. */
final class ManagedPath
{
    public function __construct(
        public readonly string $label,
        public readonly string $path,
        public readonly bool $secret,
    ) {
    }
}
