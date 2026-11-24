<?php

declare(strict_types=1);

namespace SvnManage\Security;

use SvnManage\Authz\Names;
use SvnManage\Support\IniDocument;

/**
 * Immutable view of data/twofactor.ini: one section per enrolled user with the TOTP
 * secret, the last accepted time step (replay protection) and hashed recovery codes.
 */
final class TwoFactorFile
{
    public const RECOVERY_CODES = 10;

    private function __construct(private readonly IniDocument $doc)
    {
    }

    public static function parse(string $content): self
    {
        return new self(IniDocument::parse($content, '='));
    }

    public function render(): string
    {
        return $this->doc->render();
    }

    public function isEnabled(string $user): bool
    {
        return $this->secret($user) !== '';
    }

    public function secret(string $user): string
    {
        return (string) $this->doc->get($user, 'secret', '');
    }

    public function lastStep(string $user): ?int
    {
        $step = (string) $this->doc->get($user, 'last_step', '');

        return ctype_digit($step) ? (int) $step : null;
    }

    /** Wrong codes entered since the user's last successful sign-in. */
    public function failedCodes(string $user): int
    {
        $failed = (string) $this->doc->get($user, 'failed', '0');

        return ctype_digit($failed) ? (int) $failed : 0;
    }

    public function withFailedCode(string $user): self
    {
        return $this->isEnabled($user)
            ? new self($this->doc->with($user, 'failed', (string) min(1_000_000, $this->failedCodes($user) + 1)))
            : $this;
    }

    public function withFailedCodesCleared(string $user): self
    {
        return $this->isEnabled($user) && $this->failedCodes($user) > 0 ? new self($this->doc->with($user, 'failed', '0')) : $this;
    }

    public function enabledAt(string $user): ?int
    {
        $at = (string) $this->doc->get($user, 'enabled_at', '');

        return ctype_digit($at) ? (int) $at : null;
    }

    /** @return list<string> */
    public function recoveryHashes(string $user): array
    {
        $raw = (string) $this->doc->get($user, 'recovery', '');

        return $raw === '' ? [] : explode(',', $raw);
    }

    /** @return list<string> users with two-factor enabled */
    public function users(): array
    {
        return array_values(array_filter($this->doc->sections(), fn (string $u): bool => $this->isEnabled($u)));
    }

    /** @param list<string> $recoveryCodes plain codes; only their hashes are stored */
    public function withEnrollment(string $user, string $secret, array $recoveryCodes, int $step, int $now): self
    {
        Names::user($user);
        Totp::base32Decode($secret);
        $doc = $this->doc->withoutSection($user)
            ->with($user, 'secret', $secret)
            ->with($user, 'last_step', (string) $step)
            ->with($user, 'enabled_at', (string) $now)
            ->with($user, 'recovery', implode(',', array_map(self::hashCode(...), $recoveryCodes)));

        return new self($doc);
    }

    public function withLastStep(string $user, int $step): self
    {
        return $this->isEnabled($user) ? new self($this->doc->with($user, 'last_step', (string) $step)) : $this;
    }

    /** @param list<string> $recoveryCodes */
    public function withRecoveryCodes(string $user, array $recoveryCodes): self
    {
        if (!$this->isEnabled($user)) {
            return $this;
        }

        return new self($this->doc->with($user, 'recovery', implode(',', array_map(self::hashCode(...), $recoveryCodes))));
    }

    /** Consumes a recovery code; returns null when it is not valid for the user. */
    public function withUsedRecoveryCode(string $user, string $code): ?self
    {
        $hash = self::hashCode($code);
        $hashes = $this->recoveryHashes($user);
        $found = false;
        foreach ($hashes as $candidate) {
            $found = hash_equals($candidate, $hash) || $found;
        }
        if (!$found) {
            return null;
        }
        $rest = array_values(array_filter($hashes, static fn (string $h): bool => !hash_equals($h, $hash)));

        return new self($this->doc->with($user, 'recovery', implode(',', $rest)));
    }

    public function without(string $user): self
    {
        return new self($this->doc->withoutSection($user));
    }

    /** @return list<string> */
    public static function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            // 80 random bits: far beyond offline guessing even though the stored hash is unsalted.
            $codes[] = implode('-', str_split(strtolower(Totp::base32Encode(random_bytes(10))), 4));
        }

        return $codes;
    }

    private static function hashCode(string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[^a-z2-7]/i', '', $code)));
    }
}
