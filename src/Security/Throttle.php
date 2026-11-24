<?php

declare(strict_types=1);

namespace SvnManage\Security;

/**
 * Guards one kind of secret check (login, 2FA code, setup key, current password).
 *
 * Three budgets apply at once, each reserved before the check runs:
 *  - per subject and address  (the normal "5 wrong passwords" lock)
 *  - per address              (one client guessing many names)
 *  - per subject, any address (a botnet or IPv6 rotation guessing one account)
 * Keys carry the scope, so a user named "ip" or "setup" cannot touch other budgets.
 */
final class Throttle
{
    private readonly RateLimiter $key;
    private readonly RateLimiter $address;
    private readonly RateLimiter $account;

    public function __construct(
        string $dir,
        private readonly string $scope,
        int $perKey,
        int $perAddress,
        int $perAccount,
        int $window,
    ) {
        $this->key = new RateLimiter($dir, $perKey, $window);
        $this->address = new RateLimiter($dir, $perAddress, $window);
        $this->account = new RateLimiter($dir, $perAccount, $window);
    }

    /** Seconds to wait (nothing reserved), or 0 when one attempt has been reserved. */
    public function reserve(string $subject, string $ip): int
    {
        $reserved = [];
        foreach ($this->budgets($subject, $ip) as [$limiter, $key]) {
            $wait = $limiter->attempt($key);
            if ($wait > 0) {
                foreach ($reserved as [$l, $k]) {
                    $l->refund($k);
                }

                return $wait;
            }
            $reserved[] = [$limiter, $key];
        }

        return 0;
    }

    /** The check passed: forget this subject's failures and return the shared budgets. */
    public function succeeded(string $subject, string $ip): void
    {
        [[, $key], [, $address], [, $account]] = $this->budgets($subject, $ip);
        $this->key->clear($key);
        $this->address->refund($address);
        $this->account->refund($account);
    }

    /** @return list<array{0: RateLimiter, 1: string}> */
    private function budgets(string $subject, string $ip): array
    {
        $who = Network::throttleKey($ip);

        return [
            [$this->key, $this->scope . ':key:' . $subject . '|' . $who],
            [$this->address, 'addr:' . $who],
            [$this->account, $this->scope . ':acct:' . $subject],
        ];
    }
}
