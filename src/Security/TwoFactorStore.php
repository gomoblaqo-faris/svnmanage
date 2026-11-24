<?php

declare(strict_types=1);

namespace SvnManage\Security;

use SvnManage\Support\FileStore;

/** Loads and atomically updates data/twofactor.ini (locked, with a backup per change). */
final class TwoFactorStore
{
    private ?TwoFactorFile $cached = null;

    public function __construct(
        private readonly FileStore $files,
        public readonly string $path,
    ) {
    }

    public function load(): TwoFactorFile
    {
        return $this->cached ??= TwoFactorFile::parse(is_file($this->path) ? $this->files->read($this->path) : '');
    }

    /** @param callable(TwoFactorFile): TwoFactorFile $change */
    public function update(callable $change): TwoFactorFile
    {
        $result = null;
        $this->files->update($this->path, static function (string $current) use ($change, &$result): string {
            $result = $change(TwoFactorFile::parse($current));

            return $result->render();
        });
        $this->cached = $result;

        return $result;
    }
}
