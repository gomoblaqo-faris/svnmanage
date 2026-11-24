<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Acl\Acl;
use SvnManage\Http\Response;

final class AuthController extends Controller
{
    private const MAX_USERNAME = 256;
    /** bcrypt only reads 72 bytes; legacy hashes would otherwise spend seconds on megabytes. */
    private const MAX_PASSWORD = 1024;

    public function form(): Response
    {
        if (!$this->services->authEnabled() || $this->auth->isLoggedIn()) {
            return $this->redirect('/');
        }
        $html = $this->ctx->view->page('auth/login', [
            'next' => $this->safeNext($this->request->query('next')),
            'username' => (string) $this->ctx->session->get('login_user', ''),
            'loggedOut' => $this->request->query('loggedout') === '1',
        ], $this->tr('Login'), '', 'layout-auth');

        return Response::html($html);
    }

    public function login(): Response
    {
        if (!$this->services->authEnabled()) {
            return $this->redirect('/');
        }
        $username = trim($this->request->input('username'));
        $password = $this->request->input('password');
        $next = $this->safeNext($this->request->input('next'));
        // Oversized or control-character input is a wrong login, decided before any
        // hashing, session or log write can be made expensive by it.
        $plausible = strlen($username) <= self::MAX_USERNAME && strlen($password) <= self::MAX_PASSWORD
            && !preg_match('/[\x00-\x1F\x7F]/', $username);
        $this->ctx->session->set('login_user', substr($username, 0, self::MAX_USERNAME));
        $back = fn (): Response => $this->redirect('/login', ['next' => $next === '/' ? null : $next]);

        if ($username === '' || $password === '') {
            $this->error('You have to fill out all fields.');

            return $back();
        }
        $throttle = $this->services->throttle('login');
        $subject = strtolower(substr($username, 0, self::MAX_USERNAME));
        $wait = $throttle->reserve($subject, $this->request->ip());
        if ($wait > 0) {
            $this->error('Too many failed login attempts. Try again in %0 minutes.', [(int) ceil($wait / 60)]);

            return $back();
        }

        if (!$plausible || !$this->verify($username, $password)) {
            $this->audit('login.failed', substr($username, 0, self::MAX_USERNAME));
            if (!$this->hasFlashError()) {
                $this->error('Wrong user/password combination.');
            }

            return $back();
        }

        $throttle->succeeded($subject, $this->request->ip());
        $this->ctx->session->remove('login_user');
        if ($this->services->twoFactorPolicy() !== 'off' && $this->services->twoFactor()->load()->isEnabled($username)) {
            // Password is right; the session only becomes authenticated after the code.
            $this->ctx->session->regenerate();
            $this->ctx->session->remove('user'); // nobody is signed in until the code is checked
            $this->ctx->session->set('tf_pending', ['user' => $username, 'at' => time(), 'next' => $next]);
            $this->audit('login.password_ok', $username);

            return $this->redirect('/login/verify');
        }
        $this->ctx->session->login($username);
        $this->audit('login', $username);

        return Response::redirect($this->ctx->view->url($next));
    }

    public function logout(): Response
    {
        if ($this->auth->isLoggedIn()) {
            $this->audit('logout', (string) $this->auth->username);
        }
        $this->ctx->session->destroy();

        return Response::redirect($this->ctx->view->url('/login', ['loggedout' => '1']));
    }

    public function logoutPage(): Response
    {
        return $this->redirect($this->auth->isLoggedIn() ? '/' : '/login');
    }

    private function verify(string $username, string $password): bool
    {
        $users = $this->services->users();
        if ($users === null) {
            $this->error('No user provider is configured.');

            return false;
        }
        try {
            $roles = $this->services->roles()->load();
            if (!Acl::allows($roles->rolesOf($username), Acl::MOD_BASIC, Acl::LOGIN)) {
                // Same rule as iF.SVNAdmin: only users with a role granting login may sign in.
                $users->authenticate($username, $password);

                return false;
            }

            return $users->authenticate($username, $password);
        } catch (\Throwable $e) {
            error_log('[svnmanage] login failed: ' . $e->getMessage());
            $this->error('Login is currently not possible. The details were written to the server log.');

            return false;
        }
    }

    private function hasFlashError(): bool
    {
        foreach ((array) $this->ctx->session->get('_flash', []) as $m) {
            if (is_array($m) && ($m[0] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    private function safeNext(string $next): string
    {
        if (!self::isLocalPath($next) || str_starts_with($next, '/login')) {
            return '/';
        }

        return $next;
    }
}
