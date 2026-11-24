<?php

declare(strict_types=1);

namespace SvnManage\Users;

use InvalidArgumentException;
use SvnManage\Authz\Names;
use SvnManage\Security\PasswordHasher;

/** Immutable model of an Apache htpasswd file ("user:hash" per line). */
final class HtpasswdFile
{
    /** @param array<string, string> $entries user => hash */
    private function __construct(private readonly array $entries)
    {
    }

    public static function parse(string $text): self
    {
        $entries = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && $parts[0] !== '') {
                $entries[$parts[0]] = $parts[1];
            }
        }

        return new self($entries);
    }

    /** @return list<string> */
    public function users(): array
    {
        return array_map('strval', array_keys($this->entries));
    }

    public function has(string $user): bool
    {
        return isset($this->entries[$user]) && $this->entries[$user] !== '';
    }

    public function verify(string $user, string $password): bool
    {
        if (!$this->has($user)) {
            // Same cost as a real check, so response time does not reveal which names exist.
            PasswordHasher::equaliseTiming($password);

            return false;
        }

        return PasswordHasher::verify($password, $this->entries[$user]);
    }

    public function withUser(string $user, string $password, string $algorithm): self
    {
        $user = Names::user($user);
        if ($this->has($user)) {
            throw new InvalidArgumentException(sprintf('The user "%s" already exists.', $user));
        }
        self::assertPassword($password);

        return new self([...$this->entries, $user => PasswordHasher::hash($password, $algorithm)]);
    }

    public function withPassword(string $user, string $password, string $algorithm): self
    {
        if (!$this->has($user)) {
            throw new InvalidArgumentException(sprintf('The user "%s" does not exist.', $user));
        }
        self::assertPassword($password);

        return new self([...$this->entries, $user => PasswordHasher::hash($password, $algorithm)]);
    }

    public function withoutUser(string $user): self
    {
        if (!$this->has($user)) {
            throw new InvalidArgumentException(sprintf('The user "%s" does not exist.', $user));
        }
        $entries = $this->entries;
        unset($entries[$user]);

        return new self($entries);
    }

    public function hashOf(string $user): ?string
    {
        return $this->entries[$user] ?? null;
    }

    public function render(): string
    {
        $out = '';
        foreach ($this->entries as $user => $hash) {
            $out .= $user . ':' . $hash . "\n";
        }

        return $out;
    }

    public static function assertPassword(string $password): void
    {
        if ($password === '') {
            throw new InvalidArgumentException('The password must not be empty.');
        }
    }
}
