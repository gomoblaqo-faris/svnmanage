<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Http\HttpException;
use SvnManage\Http\Response;
use SvnManage\Security\QrCode;
use SvnManage\Security\Totp;
use SvnManage\Security\TwoFactorFile;

/** TOTP second factor: the code step at sign-in, enrolment, recovery codes and resets. */
final class TwoFactorController extends Controller
{
    private const PENDING_SECONDS = 300;
    private const ISSUER = 'svnmanage';

    // ------------------------------------------------------------ sign-in step

    public function verifyForm(): Response
    {
        if ($this->pendingUser() === null) {
            return $this->redirect('/login');
        }

        return Response::html($this->ctx->view->page('auth/two-factor', [], $this->tr('Two-factor authentication'), '', 'layout-auth'));
    }

    public function verify(): Response
    {
        $user = $this->pendingUser();
        if ($user === null) {
            $this->error('Your sign-in took too long. Please log in again.');

            return $this->redirect('/login');
        }
        // Recovery codes carry 80 bits and cannot be guessed, so they have their own budget:
        // someone who burns the 6-digit budget with a stolen password cannot lock the owner out.
        $isTotp = preg_match('/^[\d\s]+$/', trim($this->request->input('code'))) === 1;
        $throttle = $this->services->throttle($isTotp ? '2fa' : '2fa-recovery');
        $wait = $throttle->reserve($user, $this->request->ip());
        if ($wait > 0) {
            $this->error($isTotp
                ? 'Too many wrong codes. Try again in %0 minutes, or sign in with one of your recovery codes.'
                : 'Too many failed login attempts. Try again in %0 minutes.', [(int) ceil($wait / 60)]);

            return $this->redirect('/login/verify');
        }
        $remaining = $this->acceptCode($user, $this->request->input('code'));
        if ($remaining === false) {
            $this->services->twoFactor()->update(static fn (TwoFactorFile $f): TwoFactorFile => $f->withFailedCode($user));
            $this->audit('login.code_failed', $user);
            $this->error('The code is not correct.');

            return $this->redirect('/login/verify');
        }
        $throttle->succeeded($user, $this->request->ip());
        $pending = (array) $this->ctx->session->get('tf_pending');
        $next = (string) ($pending['next'] ?? '/');
        $this->ctx->session->remove('tf_pending');
        $this->ctx->session->login($user);
        if (($pending['proxy'] ?? false) === true) {
            $this->ctx->session->set('via_proxy', true);
        }
        $this->audit('login', $user, ['second_factor' => $remaining === null ? 'totp' : 'recovery']);
        $failed = $this->services->twoFactor()->load()->failedCodes($user);
        if ($failed > 0) {
            $this->services->twoFactor()->update(static fn (TwoFactorFile $f): TwoFactorFile => $f->withFailedCodesCleared($user));
            $this->ctx->session->flash('warning', $this->tr('There were %0 wrong two-factor codes since your last sign-in. Someone else may know your password, so change your password now.', [$failed]));
        }
        if ($remaining !== null) {
            $this->info('You used a recovery code. %0 recovery codes left.', [$remaining]);
        }

        return Response::redirect($this->ctx->view->url(self::isLocalPath($next) ? $next : '/'));
    }

    // ------------------------------------------------------------ account page

    public function show(): Response
    {
        $user = $this->requireUser();
        $file = $this->services->twoFactor()->load();
        $enabled = $file->isEnabled($user);
        $vars = [
            'enabled' => $enabled,
            'required' => $this->services->twoFactorRequiredFor($user),
            'policyOff' => $this->services->twoFactorPolicy() === 'off',
            'codes' => $this->takeShownCodes(),
            'recoveryLeft' => count($file->recoveryHashes($user)),
            'enabledAt' => $file->enabledAt($user),
        ];
        if (!$enabled) {
            $secret = (string) $this->ctx->session->get('tf_secret', '');
            if ($secret === '') {
                $secret = Totp::generateSecret();
                $this->ctx->session->set('tf_secret', $secret);
            }
            $uri = Totp::uri($secret, $user, self::ISSUER);
            $vars += ['secret' => $secret, 'uri' => $uri, 'qr' => QrCode::svg($uri, $this->tr('QR code for your authenticator app'))];
        }

        return $this->page('account/two-factor', $vars, 'Two-factor authentication', 'account.2fa');
    }

    public function enable(): Response
    {
        $user = $this->requireUser();
        $this->requireNotOff();
        if ($this->services->twoFactor()->load()->isEnabled($user)) {
            throw HttpException::forbidden('Two-factor authentication is already on. Turn it off first to use another phone.');
        }
        // The password is asked again, so a stolen session cannot tie the account to the thief's phone.
        if ($this->ctx->session->get('via_proxy') !== true
            && !$this->throttledCheck($user, fn (): bool => $this->passwordMatches($user), 'The password is not correct.')) {
            return $this->redirect('/account/two-factor');
        }
        $secret = (string) $this->ctx->session->get('tf_secret', '');
        $step = $secret === '' ? null : Totp::verify($secret, $this->request->input('code'), time(), null);
        if ($step === null) {
            $this->error('The code is not correct. Check the time on your phone and try again.');

            return $this->redirect('/account/two-factor');
        }
        $codes = TwoFactorFile::generateRecoveryCodes();
        $this->services->twoFactor()->update(static fn (TwoFactorFile $f): TwoFactorFile => $f->withEnrollment($user, $secret, $codes, $step, time()));
        $this->ctx->session->remove('tf_secret');
        $this->ctx->session->set('tf_codes', $codes);
        $this->endOtherSessions($user);
        $this->audit('2fa.enable', $user);
        $this->success('Two-factor authentication is on. Store your recovery codes somewhere safe.');

        return $this->redirect('/account/two-factor');
    }

    public function regenerate(): Response
    {
        $user = $this->requireUser();
        if (!$this->throttledCheck($user, fn (): bool => $this->acceptCode($user, $this->request->input('code'), false) !== false)) {
            return $this->redirect('/account/two-factor');
        }
        $codes = TwoFactorFile::generateRecoveryCodes();
        $this->services->twoFactor()->update(static fn (TwoFactorFile $f): TwoFactorFile => $f->withRecoveryCodes($user, $codes));
        $this->ctx->session->set('tf_codes', $codes);
        $this->audit('2fa.recovery_codes', $user);
        $this->success('New recovery codes were created. The old ones no longer work.');

        return $this->redirect('/account/two-factor');
    }

    public function disable(): Response
    {
        $user = $this->requireUser();
        if ($this->services->twoFactorRequiredFor($user)) {
            throw HttpException::forbidden('Two-factor authentication is required for your account.');
        }
        $valid = fn (): bool => $this->passwordMatches($user)
            && $this->acceptCode($user, $this->request->input('code'), false) !== false;
        if (!$this->throttledCheck($user, $valid, 'The password or the code is not correct.')) {
            return $this->redirect('/account/two-factor');
        }
        $this->services->twoFactor()->update(static fn (TwoFactorFile $f): TwoFactorFile => $f->without($user));
        $this->audit('2fa.disable', $user);
        $this->success('Two-factor authentication is off.');

        return $this->redirect('/account/two-factor');
    }

    /** For a lost phone: an administrator (or user manager) removes another user's second factor. */
    public function reset(string $name): Response
    {
        $this->authorize(Acl::MOD_USER, Acl::CHANGEPASS_OTHER);
        if ($name === $this->auth->username) {
            throw HttpException::forbidden('Use your own two-factor page instead.');
        }
        $roles = $this->services->roles()->load();
        if ($roles->hasRole($name, Acl::ADMINISTRATOR) && !$this->auth->can(Acl::MOD_ROLE, Acl::ASSIGN_ADMIN)) {
            throw HttpException::forbidden('Only administrators can change administrator accounts.');
        }
        $this->services->twoFactor()->update(static fn (TwoFactorFile $f): TwoFactorFile => $f->without($name));
        $this->services->revocations()->revoke($name);
        $this->audit('2fa.reset', $name);
        $this->success('Two-factor authentication was reset for %0. They can set it up again after signing in.', [$name]);

        return $this->redirect('/users/' . rawurlencode($name));
    }

    // ------------------------------------------------------------ helpers

    /**
     * Checks a TOTP code (and, when allowed, a recovery code) and records its use.
     * Returns false when invalid, null for a TOTP code, or the recovery codes left.
     */
    private function acceptCode(string $user, string $input, bool $allowRecovery = true): int|false|null
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 64) {
            return false;
        }
        $store = $this->services->twoFactor();
        $file = $store->load();
        if (!$file->isEnabled($user)) {
            return false;
        }
        if (preg_match('/^[\d\s]+$/', $input)) {
            // Checked inside the locked update, so two parallel requests cannot both use one code.
            $ok = false;
            $now = time();
            $store->update(static function (TwoFactorFile $f) use ($user, $input, $now, &$ok): TwoFactorFile {
                $step = $f->isEnabled($user) ? Totp::verify($f->secret($user), $input, $now, $f->lastStep($user)) : null;
                if ($step === null) {
                    return $f;
                }
                $ok = true;

                return $f->withLastStep($user, $step);
            });

            return $ok ? null : false;
        }
        if (!$allowRecovery) {
            return false;
        }
        $left = false;
        $store->update(static function (TwoFactorFile $f) use ($user, $input, &$left): TwoFactorFile {
            $next = $f->withUsedRecoveryCode($user, $input);
            if ($next === null) {
                return $f;
            }
            $left = count($next->recoveryHashes($user));

            return $next;
        });

        return $left;
    }

    /**
     * Runs a credential check under the user's 2FA throttle (shared with the sign-in
     * step), so a stolen session cannot be used to brute-force the six digits.
     *
     * @param callable(): bool $check
     */
    private function throttledCheck(string $user, callable $check, string $failure = 'The code is not correct.'): bool
    {
        $throttle = $this->services->throttle('2fa');
        $wait = $throttle->reserve($user, $this->request->ip());
        if ($wait > 0) {
            $this->error('Too many failed login attempts. Try again in %0 minutes.', [(int) ceil($wait / 60)]);

            return false;
        }
        if (!$check()) {
            $this->audit('2fa.check_failed', $user);
            $this->error($failure);

            return false;
        }
        $throttle->succeeded($user, $this->request->ip());

        return true;
    }

    private function passwordMatches(string $user): bool
    {
        $provider = $this->services->users();
        $password = $this->request->input('current');

        return $provider !== null && $password !== '' && strlen($password) <= 1024 && $provider->authenticate($user, $password);
    }

    private function pendingUser(): ?string
    {
        $pending = $this->ctx->session->get('tf_pending');
        if (!is_array($pending) || !is_string($pending['user'] ?? null) || time() - (int) ($pending['at'] ?? 0) > self::PENDING_SECONDS) {
            $this->ctx->session->remove('tf_pending');

            return null;
        }

        return $pending['user'];
    }

    private function requireUser(): string
    {
        $this->requireLogin();
        if ($this->auth->username === null) {
            throw HttpException::forbidden('Two-factor authentication needs authentication to be enabled.');
        }

        return $this->auth->username;
    }

    private function requireNotOff(): void
    {
        if ($this->services->twoFactorPolicy() === 'off') {
            throw HttpException::inactive('Two-factor authentication is turned off in the settings.');
        }
    }

    /** @return list<string> codes to show exactly once */
    private function takeShownCodes(): array
    {
        $codes = $this->ctx->session->get('tf_codes', []);
        $this->ctx->session->remove('tf_codes');

        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }

    private function endOtherSessions(string $user): void
    {
        $this->services->revocations()->revoke($user);
        $this->ctx->session->set('login_at', microtime(true)); // after the revocation: this session stays
    }
}
