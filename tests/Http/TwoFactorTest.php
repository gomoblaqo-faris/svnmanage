<?php

declare(strict_types=1);

namespace SvnManage\Tests\Http;

use SvnManage\Security\Totp;

final class TwoFactorTest extends HttpTestCase
{
    private string $password = 'Secret123';

    private function enrol(): array
    {
        $page = $this->get('/account/two-factor')->body;
        self::assertStringContainsString('<svg', $page, 'QR code is shown');
        $secret = (string) ($_SESSION['tf_secret'] ?? '');
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertStringContainsString(trim(chunk_split($secret, 4, ' ')), $page, 'secret shown for manual entry');

        $this->post('/account/two-factor', ['code' => '000000', 'current' => $this->password]);
        self::assertStringContainsString('not correct', $this->flashText());
        $this->post('/account/two-factor', ['code' => Totp::code($secret, intdiv(time(), 30)), 'current' => $this->password]);
        $codes = $_SESSION['tf_codes'] ?? [];
        $page = $this->get('/account/two-factor')->body;
        self::assertCount(10, $codes);
        self::assertStringContainsString($codes[0], $page, 'recovery codes are shown once');
        self::assertStringNotContainsString($codes[0], $this->get('/account/two-factor')->body, '…and only once');
        self::assertStringNotContainsString($codes[0], $this->file('twofactor.ini'), 'recovery codes are stored hashed');

        return [$secret, $codes];
    }

    private function passwordStep(string $user = 'admin', string $password = 'Secret123'): void
    {
        $this->logout();
        $this->post('/login', ['username' => $user, 'password' => $password]);
    }

    public function testEnrolmentAndLoginWithCode(): void
    {
        $this->install();
        [$secret] = $this->enrol();
        self::assertStringContainsString('[admin]', $this->file('twofactor.ini'));

        $this->passwordStep();
        self::assertArrayNotHasKey('user', $_SESSION, 'password alone is not enough');
        self::assertSame(303, $this->get('/')->status);
        self::assertSame(200, $this->get('/login/verify')->status);

        $this->post('/login/verify', ['code' => '123456']);
        self::assertStringContainsString('not correct', $this->flashText());
        self::assertArrayNotHasKey('user', $_SESSION);

        // The next window is accepted (clock drift); the enrolment code's window is used up.
        $code = Totp::code($secret, intdiv(time(), 30) + 1);
        $this->post('/login/verify', ['code' => $code]);
        self::assertSame('admin', $_SESSION['user'] ?? null);

        $this->passwordStep();
        $this->post('/login/verify', ['code' => $code]);
        self::assertArrayNotHasKey('user', $_SESSION, 'a used code cannot be replayed');
    }

    public function testRecoveryCodeWorksOnce(): void
    {
        $this->install();
        [, $codes] = $this->enrol();
        $this->passwordStep();
        $this->post('/login/verify', ['code' => strtoupper($codes[3])]);
        self::assertSame('admin', $_SESSION['user'] ?? null);
        self::assertStringContainsString('9 recovery codes left', $this->flashText());

        $this->passwordStep();
        $this->post('/login/verify', ['code' => $codes[3]]);
        self::assertArrayNotHasKey('user', $_SESSION);
    }

    public function testCodeEntryIsThrottled(): void
    {
        $this->install();
        [$secret] = $this->enrol();
        $this->passwordStep();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login/verify', ['code' => sprintf('%06d', $i)]);
            $this->flashes();
        }
        $this->post('/login/verify', ['code' => Totp::code($secret, intdiv(time(), 30))]);
        self::assertStringContainsString('Too many wrong codes', $this->flashText());
        self::assertArrayNotHasKey('user', $_SESSION);
    }

    public function testPendingLoginExpires(): void
    {
        $this->install();
        [$secret] = $this->enrol();
        $this->passwordStep();
        $_SESSION['tf_pending']['at'] = time() - 301;
        $this->post('/login/verify', ['code' => Totp::code($secret, intdiv(time(), 30))]);
        self::assertArrayNotHasKey('user', $_SESSION);
    }

    public function testPolicyForcesAdministratorsToEnrol(): void
    {
        $this->install();
        $this->post('/settings', ['Common|TwoFactor' => 'admins']);
        $redirect = $this->get('/users');
        self::assertSame(303, $redirect->status);
        self::assertStringContainsString('/account/two-factor', $redirect->headers['Location'] ?? '');
        self::assertSame(200, $this->get('/account/two-factor')->status);
        $this->enrol();
        self::assertSame(200, $this->get('/users')->status);
    }

    public function testDisableNeedsPasswordAndCodeAndIsRefusedWhenRequired(): void
    {
        $this->install();
        [$secret] = $this->enrol();
        $this->post('/account/two-factor/disable', ['current' => 'wrong', 'code' => Totp::code($secret, intdiv(time(), 30) + 1)]);
        self::assertStringContainsString('[admin]', $this->file('twofactor.ini'));
        $this->post('/account/two-factor/disable', ['current' => 'Secret123', 'code' => Totp::code($secret, intdiv(time(), 30) + 1)]);
        self::assertStringNotContainsString('[admin]', $this->file('twofactor.ini'));
    }

    public function testAdministratorCanResetAnotherUser(): void
    {
        $this->install();
        $this->post('/users', ['username' => 'dave', 'password' => 'Dave123456', 'password2' => 'Dave123456', 'roles' => ['User']]);
        $adminSession = $_SESSION;
        $this->passwordStep('dave', 'Dave123456');
        self::assertSame('dave', $_SESSION['user'] ?? null);
        $this->password = 'Dave123456';
        $this->enrol();
        $_SESSION = $adminSession;
        self::assertStringContainsString('Two-factor', $this->get('/users/dave')->body);
        $this->post('/users/dave/two-factor/reset');
        self::assertStringNotContainsString('[dave]', $this->file('twofactor.ini'));
    }

    public function testDeletingAUserRemovesTheirSecret(): void
    {
        $this->install();
        $this->post('/users', ['username' => 'erin', 'password' => 'Erin123456', 'password2' => 'Erin123456', 'roles' => ['User']]);
        $adminSession = $_SESSION;
        $this->passwordStep('erin', 'Erin123456');
        $this->password = 'Erin123456';
        $this->enrol();
        $_SESSION = $adminSession;
        $this->post('/users/delete', ['selected_users' => ['erin']]);
        self::assertStringNotContainsString('[erin]', $this->file('twofactor.ini'));
    }

    public function testCodeChecksOnTheAccountPageAreThrottled(): void
    {
        $this->install();
        [$secret] = $this->enrol();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/account/two-factor/recovery', ['code' => sprintf('%06d', $i)]);
            $this->flashes();
        }
        $this->post('/account/two-factor/recovery', ['code' => Totp::code($secret, intdiv(time(), 30) + 1)]);
        self::assertStringContainsString('Too many failed', $this->flashText());
    }

    public function testEnrolmentNeedsThePasswordAndCannotReplaceAnActiveSecret(): void
    {
        $this->install();
        $this->get('/account/two-factor');
        $secret = (string) $_SESSION['tf_secret'];
        $this->post('/account/two-factor', ['code' => Totp::code($secret, intdiv(time(), 30)), 'current' => 'wrong']);
        self::assertStringNotContainsString('[admin]', $this->file('twofactor.ini'), 'a hijacked session cannot enrol its own phone');
        self::assertStringContainsString('not correct', $this->flashText());

        [$first] = $this->enrol();
        $_SESSION['tf_secret'] = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $this->post('/account/two-factor', ['code' => Totp::code('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', intdiv(time(), 30)), 'current' => 'Secret123']);
        self::assertStringContainsString('secret=' . $first, $this->file('twofactor.ini'), 'an active secret is not replaced');
    }

    public function testRecoveryCodesCarryEightyBits(): void
    {
        foreach (\SvnManage\Security\TwoFactorFile::generateRecoveryCodes() as $code) {
            self::assertMatchesRegularExpression('/^[a-z2-7]{4}-[a-z2-7]{4}-[a-z2-7]{4}-[a-z2-7]{4}$/', $code);
        }
    }

    public function testRecoveryCodeStillWorksWhenCodeGuessingLockedTheAccount(): void
    {
        $this->install();
        [, $codes] = $this->enrol();
        // Someone who knows the password burns the account-wide code budget from many addresses.
        for ($i = 1; $i <= 12; $i++) {
            $this->passwordStep();
            $this->post('/login/verify', ['code' => '000000'], ['REMOTE_ADDR' => "203.0.113.$i"]);
            $this->flashes();
        }
        $this->passwordStep();
        $this->post('/login/verify', ['code' => '111111']);
        self::assertStringContainsString('recovery code', $this->flashText(), 'the lock message points to the way out');
        $this->passwordStep();
        $this->post('/login/verify', ['code' => $codes[0]]);
        self::assertSame('admin', $_SESSION['user'] ?? null, 'recovery codes (80 bits) are not blocked by the code lock');
    }

    public function testUserIsWarnedAboutWrongCodesAfterSigningIn(): void
    {
        $this->install();
        [$secret] = $this->enrol();
        $this->passwordStep();
        $this->post('/login/verify', ['code' => '000000']);
        $this->post('/login/verify', ['code' => '000001']);
        $this->flashes();
        $this->post('/login/verify', ['code' => Totp::code($secret, intdiv(time(), 30) + 1)]);
        $flash = $this->flashText();
        self::assertStringContainsString('2 wrong two-factor codes', $flash);
        self::assertStringContainsString('change your password', $flash);
        self::assertStringContainsString('Wrong two-factor codes', $this->get('/')->body, 'administrators see it on the dashboard');
    }

    public function testProxyAuthenticatedUsersStillNeedTheirCode(): void
    {
        $this->install();
        [$secret] = $this->enrol();
        $this->post('/settings', ['Common|TrustedProxies' => '127.0.0.1', 'Common|HttpProxyAuth' => '1']);
        $this->logout();
        $proxy = ['X-Forwarded-User' => 'admin'];
        $first = $this->get('/users', [], $proxy);
        self::assertSame(303, $first->status);
        self::assertStringEndsWith('/login/verify', $first->headers['Location'] ?? '');
        self::assertArrayNotHasKey('user', $_SESSION, 'the proxy vouches for the password step only');
        self::assertSame(200, $this->get('/login/verify', [], $proxy)->status);
        $this->post('/login/verify', ['code' => Totp::code($secret, intdiv(time(), 30) + 1)], $proxy);
        self::assertSame('admin', $_SESSION['user'] ?? null);
        self::assertSame(200, $this->get('/users', [], $proxy)->status);
    }

    public function testRecoveryCodesHaveNoAccountWideLock(): void
    {
        $this->install();
        [, $codes] = $this->enrol();
        for ($i = 1; $i <= 120; $i++) {
            $this->passwordStep();
            $this->post('/login/verify', ['code' => 'aaaa-bbbb-cccc-' . sprintf('%04d', $i)], ['REMOTE_ADDR' => '198.51.' . intdiv($i, 250) . '.' . ($i % 250)]);
            $this->flashes();
        }
        $this->passwordStep();
        $this->post('/login/verify', ['code' => $codes[1]]);
        self::assertSame('admin', $_SESSION['user'] ?? null, 'no number of bad recovery attempts from elsewhere locks the owner out');
    }
}
