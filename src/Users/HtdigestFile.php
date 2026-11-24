<?php

declare(strict_types=1);

namespace SvnManage\Users;

use InvalidArgumentException;
use SvnManage\Authz\Names;

/**
 * Immutable model of an Apache htdigest file ("user:realm:md5").
 * Lines of other realms are preserved untouched.
 */
final class HtdigestFile
{
    /**
     * @param array<string, string> $entries user => hash (for $realm)
     * @param list<string> $foreign lines of other realms
     */
    private function __construct(
        private readonly string $realm,
        private readonly array $entries,
        private readonly array $foreign,
    ) {
    }

    public static function parse(string $text, string $realm): self
    {
        $entries = [];
        $foreign = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line);
            if (count($parts) === 3 && $parts[1] === $realm) {
                $entries[$parts[0]] = $parts[2];
            } else {
                $foreign[] = $line;
            }
        }

        return new self($realm, $entries, $foreign);
    }

    public function realm(): string
    {
        return $this->realm;
    }

    /** @return list<string> */
    public function users(): array
    {
        return array_map('strval', array_keys($this->entries));
    }

    public function has(string $user): bool
    {
        return isset($this->entries[$user]);
    }

    public function verify(string $user, string $password): bool
    {
        return $this->has($user) && hash_equals($this->entries[$user], $this->digest($user, $password));
    }

    public function withUser(string $user, string $password): self
    {
        $user = Names::user($user);
        if ($this->has($user)) {
            throw new InvalidArgumentException(sprintf('The user "%s" already exists.', $user));
        }
        HtpasswdFile::assertPassword($password);

        return new self($this->realm, [...$this->entries, $user => $this->digest($user, $password)], $this->foreign);
    }

    public function withPassword(string $user, string $password): self
    {
        if (!$this->has($user)) {
            throw new InvalidArgumentException(sprintf('The user "%s" does not exist.', $user));
        }
        HtpasswdFile::assertPassword($password);

        return new self($this->realm, [...$this->entries, $user => $this->digest($user, $password)], $this->foreign);
    }

    public function withoutUser(string $user): self
    {
        if (!$this->has($user)) {
            throw new InvalidArgumentException(sprintf('The user "%s" does not exist.', $user));
        }
        $entries = $this->entries;
        unset($entries[$user]);

        return new self($this->realm, $entries, $this->foreign);
    }

    public function render(): string
    {
        $out = '';
        foreach ($this->entries as $user => $hash) {
            $out .= $user . ':' . $this->realm . ':' . $hash . "\n";
        }
        foreach ($this->foreign as $line) {
            $out .= $line . "\n";
        }

        return $out;
    }

    private function digest(string $user, string $password): string
    {
        return md5($user . ':' . $this->realm . ':' . $password);
    }
}
