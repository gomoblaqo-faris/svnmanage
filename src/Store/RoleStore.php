<?php

declare(strict_types=1);

namespace SvnManage\Store;

use SvnManage\Acl\RoleAssignments;
use SvnManage\Support\FileStore;

/** Loads and atomically updates userroleassignments.ini. */
final class RoleStore
{
    private ?RoleAssignments $cached = null;

    public function __construct(
        private readonly FileStore $files,
        public readonly string $path,
    ) {
    }

    public function load(): RoleAssignments
    {
        if ($this->cached === null) {
            if (file_exists($this->path) && !is_file($this->path)) {
                throw new \RuntimeException(sprintf('The role assignment file is not a regular file: %s', $this->path));
            }
            $this->cached = RoleAssignments::parse(is_file($this->path) ? $this->files->read($this->path) : '');
        }

        return $this->cached;
    }

    /** @param callable(RoleAssignments): RoleAssignments $change */
    public function update(callable $change): RoleAssignments
    {
        $result = null;
        $this->files->update($this->path, static function (string $current) use ($change, &$result): string {
            $result = $change(RoleAssignments::parse($current));

            return $result->render();
        });
        $this->cached = $result;

        return $result;
    }
}
