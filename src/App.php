<?php

declare(strict_types=1);

namespace SvnManage;

use SvnManage\Acl\Authorizer;
use SvnManage\Config\Config;
use SvnManage\Controllers as C;
use SvnManage\Http\Context;
use SvnManage\Http\HttpException;
use SvnManage\Http\Request;
use SvnManage\Http\Response;
use SvnManage\Http\Router;
use SvnManage\Http\Session;
use SvnManage\Security\FormToken;
use SvnManage\I18n\Translator;
use SvnManage\View\View;
use SvnManage\Security\Network;
use Throwable;

/** Front controller kernel: bootstraps config, session, auth and dispatches. */
final class App
{
    /** POST targets of the anonymous login page, which carries a stateless form token. */
    private const STATELESS_FORM_PATHS = ['/login', '/locale'];

    public readonly string $dataDir;

    public function __construct(
        public readonly string $appRoot,
        ?string $dataDir = null,
        private readonly ?Session $session = null,
    ) {
        $env = getenv('SVNMANAGE_DATA');
        $this->dataDir = rtrim($dataDir ?? ($env !== false && $env !== '' ? $env : $appRoot . '/data'), '/');
    }

    public static function run(string $appRoot): void
    {
        (new self($appRoot))->handle(Request::fromGlobals())->send();
    }

    public function handle(Request $request): Response
    {
        $session = $this->session ?? new Session();
        try {
            $services = $this->boot();
        } catch (Throwable $e) {
            return $this->secure(Response::html($this->bootError($e), 500), $request);
        }
        $request = $request->withTrustedProxies($this->networkList($services, 'TrustedProxies'));
        $allowed = $this->networkList($services, 'AllowedNetworks');
        if ($allowed !== [] && !Network::matchesAny($request->ip(), $allowed)) {
            // Answer before sessions, routing or templates: nothing about the app is revealed.
            return $this->secure(Response::html('<!doctype html><title>403 Forbidden</title><h1>403 Forbidden</h1>', 403), $request);
        }
        if ($services->config->bool('Common', 'ForceHttps') && !$request->isSecure()) {
            if ($request->claimsHttps()) {
                // Redirecting would only bring the same request back: explain instead of looping.
                error_log(sprintf('[svnmanage] ForceHttps: a request from %s says it used HTTPS (forwarded headers), but its sender is not a trusted proxy, so svnmanage cannot confirm it; answered 403 instead of redirecting in a loop. Fix: have the web server set HTTPS=on for requests from the proxy or tunnel (needed when mod_remoteip already rewrites the client address), or list the proxy under TrustedProxies. If you cannot reach Settings, set ForceHttps=false in config.ini by hand.', $request->ip()));

                return $this->secure(Response::html(self::HTTPS_UNTRUSTED_PAGE, 403), $request);
            }

            return $this->secure($this->toHttps($request), $request);
        }
        if (!$services->authEnabled() && !self::noAuthAllowed($request)) {
            error_log('[svnmanage] refused remote access while authentication is off');

            return $this->secure(Response::html('<!doctype html><title>403 Forbidden</title><h1>403 Forbidden</h1><p>Authentication is turned off, so svnmanage only answers on this server itself.</p>', 403), $request);
        }
        $session->start(
            $request->isSecure(),
            $request->basePath,
            Session::isNeeded($request) || $this->isSignedLoginPost($request, $services) || $this->carriesProxyUser($request, $services),
            $services->config->int('Common', 'SessionTimeout', 3600),
        );

        $locale = $request->cookie('locale') ?? $services->config->string('GUI', 'DefaultLocale', 'en_US');
        $tr = Translator::load($this->appRoot . '/lang', $locale);
        $auth = $this->resolveAuth($request, $services, $session);
        $view = new View($this->appRoot . '/templates', $tr, $request, $session, $auth, $services);
        $ctx = new Context($request, $services, $session, $auth, $tr, $view);

        try {
            $response = $this->dispatch($ctx);
        } catch (HttpException $e) {
            $response = $this->httpError($ctx, $e);
        } catch (Throwable $e) {
            error_log('[svnmanage] ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            // Details (paths, svn stderr, LDAP DNs) only for administrators.
            $message = $auth->isAdmin() ? $e->getMessage() : 'An unexpected error occurred. The details were written to the server log.';
            $response = $this->httpError($ctx, new HttpException(500, $message, 'error'));
        }
        if ($view->formNonce() !== null) {
            $response = $response->withHeader('Set-Cookie', $this->formCookie($request, $view->formNonce()));
        }

        return $this->secure($response, $ctx->request);
    }

    /** Proxy-authenticated requests keep a session (for the two-factor step and CSRF). */
    private function carriesProxyUser(Request $request, Services $services): bool
    {
        return $services->config->bool('Common', 'HttpProxyAuth')
            && ($request->server('REMOTE_USER') !== '' || ($request->isFromTrustedProxy() && $request->header('X-Forwarded-User') !== ''));
    }

    /** A login POST carrying a valid stateless form token: worth a session (for its result). */
    private function isSignedLoginPost(Request $request, Services $services): bool
    {
        return $request->isPost() && $request->path === '/login' && $services->formTokens()->verify(
            $request->input('_token'),
            (string) $request->cookie(Session::formCookieName($request->isSecure(), $request->basePath)),
        );
    }

    private function formCookie(Request $request, string $nonce): string
    {
        return Session::formCookieName($request->isSecure(), $request->basePath) . '=' . $nonce
            . '; Max-Age=' . FormToken::MAX_AGE . '; Path=' . ($request->basePath === '' ? '/' : $request->basePath . '/')
            . '; HttpOnly; SameSite=Strict' . ($request->isSecure() ? '; Secure' : '');
    }

    /** Required-but-missing second factor: everything except the enrolment page is closed. */
    private function mustEnrolFirst(Context $ctx, string $path): bool
    {
        $user = $ctx->auth->username;
        if ($user === null || !$ctx->auth->authEnabled) {
            return false;
        }
        if ($path === '/account/two-factor' || str_starts_with($path, '/account/two-factor/') || in_array($path, ['/logout', '/locale'], true)) {
            return false;
        }

        return $ctx->services->twoFactorRequiredFor($user) && !$ctx->services->twoFactor()->load()->isEnabled($user);
    }

    /** With authentication off everyone is an administrator: only allow that locally. */
    public static function noAuthAllowed(Request $request): bool
    {
        if (getenv('SVNMANAGE_ALLOW_NOAUTH') === '1') {
            return true;
        }
        // A reverse proxy on this host makes every client look local unless it is listed
        // as trusted; forwarded headers from an unlisted source therefore mean "remote".
        $forwarded = $request->server('HTTP_X_FORWARDED_FOR') !== '' || $request->server('HTTP_FORWARDED') !== ''
            || $request->server('HTTP_X_REAL_IP') !== '';

        return in_array($request->ip(), ['127.0.0.1', '::1'], true) && ($request->isFromTrustedProxy() || !$forwarded);
    }

    /** Deliberately vague for visitors: the cause and the fix are in the server's error log. */
    private const HTTPS_UNTRUSTED_PAGE = '<!doctype html><title>HTTPS required</title><h1>HTTPS required</h1>'
        . '<p>This site requires HTTPS, and this connection could not be confirmed as HTTPS. Administrators: see the web server\'s error log.</p>';

    private function toHttps(Request $request): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return Response::html('<!doctype html><title>HTTPS required</title><h1>HTTPS required</h1>', 403);
        }
        $host = $request->header('Host');
        if (!preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/D', $host)) {
            $host = (string) $request->server('SERVER_NAME');
        }
        $host = (string) preg_replace('/:\d+$/', '', $host);
        $query = $request->queryString();
        $url = 'https://' . $host . $request->basePath . $request->path . ($query === '' ? '' : '?' . $query);

        return new Response(302, '', ['Location' => $url]);
    }

    /** @return list<string> */
    private function networkList(Services $services, string $key): array
    {
        try {
            return Network::parseList($services->config->string('Common', $key, ''), $key === 'TrustedProxies');
        } catch (\InvalidArgumentException $e) {
            error_log('[svnmanage] ignoring invalid ' . $key . ': ' . $e->getMessage());

            // An unreadable allow-list must fail closed; a broken proxy list just trusts nobody.
            return $key === 'AllowedNetworks' ? ['0.0.0.0/32'] : [];
        }
    }

    public function router(): Router
    {
        return (new Router())
            ->get('/', [C\DashboardController::class, 'index'])
            ->get('/login', [C\AuthController::class, 'form'])
            ->post('/login', [C\AuthController::class, 'login'])
            ->post('/logout', [C\AuthController::class, 'logout'])
            ->get('/logout', [C\AuthController::class, 'logoutPage'])
            ->post('/locale', [C\LocaleController::class, 'switch'])
            ->get('/setup', [C\SetupController::class, 'index'])
            ->post('/setup', [C\SetupController::class, 'save'])
            ->post('/setup/admin', [C\SetupController::class, 'admin'])
            ->post('/setup/unlock', [C\SetupController::class, 'unlock'])
            ->get('/login/verify', [C\TwoFactorController::class, 'verifyForm'])
            ->post('/login/verify', [C\TwoFactorController::class, 'verify'])
            ->get('/account/two-factor', [C\TwoFactorController::class, 'show'])
            ->post('/account/two-factor', [C\TwoFactorController::class, 'enable'])
            ->post('/account/two-factor/recovery', [C\TwoFactorController::class, 'regenerate'])
            ->post('/account/two-factor/disable', [C\TwoFactorController::class, 'disable'])
            ->post('/users/{name}/two-factor/reset', [C\TwoFactorController::class, 'reset'])
            ->post('/setup/test', [C\SettingsController::class, 'test'])
            ->get('/settings', [C\SettingsController::class, 'index'])
            ->post('/settings', [C\SettingsController::class, 'save'])
            ->post('/settings/test', [C\SettingsController::class, 'test'])
            ->get('/repositories', [C\RepositoryController::class, 'index'])
            ->get('/repositories/new', [C\RepositoryController::class, 'create'])
            ->post('/repositories', [C\RepositoryController::class, 'store'])
            ->post('/repositories/delete', [C\RepositoryController::class, 'delete'])
            ->get('/repositories/{parent}/{name}', [C\RepositoryController::class, 'show'])
            ->get('/repositories/{parent}/{name}/browse', [C\RepositoryController::class, 'browse'])
            ->get('/repositories/{parent}/{name}/file', [C\RepositoryController::class, 'file'])
            ->post('/repositories/{parent}/{name}/dump', [C\RepositoryController::class, 'dump'])
            ->post('/repositories/{parent}/{name}/load', [C\RepositoryController::class, 'load'])
            ->get('/users', [C\UserController::class, 'index'])
            ->get('/users/new', [C\UserController::class, 'create'])
            ->post('/users', [C\UserController::class, 'store'])
            ->post('/users/delete', [C\UserController::class, 'delete'])
            ->post('/users/roles', [C\UserController::class, 'bulkRole'])
            ->get('/account/password', [C\UserController::class, 'ownPassword'])
            ->get('/users/{name}', [C\UserController::class, 'show'])
            ->post('/users/{name}/groups', [C\UserController::class, 'addGroups'])
            ->post('/users/{name}/groups/remove', [C\UserController::class, 'removeGroups'])
            ->post('/users/{name}/roles', [C\UserController::class, 'addRoles'])
            ->post('/users/{name}/roles/remove', [C\UserController::class, 'removeRoles'])
            ->post('/users/{name}/permissions/remove', [C\UserController::class, 'removePermissions'])
            ->post('/users/{name}/managed/remove', [C\UserController::class, 'removeManaged'])
            ->get('/users/{name}/password', [C\UserController::class, 'passwordForm'])
            ->post('/users/{name}/password', [C\UserController::class, 'password'])
            ->get('/groups', [C\GroupController::class, 'index'])
            ->get('/groups/new', [C\GroupController::class, 'create'])
            ->post('/groups', [C\GroupController::class, 'store'])
            ->post('/groups/delete', [C\GroupController::class, 'delete'])
            ->get('/groups/memberships', [C\GroupController::class, 'memberships'])
            ->post('/groups/memberships', [C\GroupController::class, 'saveMemberships'])
            ->get('/groups/{name}', [C\GroupController::class, 'show'])
            ->post('/groups/{name}/members', [C\GroupController::class, 'addMembers'])
            ->post('/groups/{name}/members/remove', [C\GroupController::class, 'removeMembers'])
            ->post('/groups/{name}/rename', [C\GroupController::class, 'rename'])
            ->post('/groups/{name}/permissions/remove', [C\GroupController::class, 'removePermissions'])
            ->get('/access-paths', [C\AccessPathController::class, 'index'])
            ->get('/access-paths/new', [C\AccessPathController::class, 'create'])
            ->post('/access-paths', [C\AccessPathController::class, 'store'])
            ->post('/access-paths/delete', [C\AccessPathController::class, 'delete'])
            ->post('/access-paths/managers', [C\AccessPathController::class, 'assignManagers'])
            ->post('/access-paths/managers/remove', [C\AccessPathController::class, 'removeManagers'])
            ->get('/access-paths/view', [C\AccessPathController::class, 'show'])
            ->post('/access-paths/grant', [C\AccessPathController::class, 'grant'])
            ->post('/access-paths/revoke', [C\AccessPathController::class, 'revoke'])
            ->get('/permissions', [C\PermissionController::class, 'index'])
            ->post('/permissions', [C\PermissionController::class, 'store'])
            ->get('/roles', [C\RoleController::class, 'index'])
            ->get('/sync', [C\SyncController::class, 'index'])
            ->post('/sync', [C\SyncController::class, 'run'])
            ->get('/activity', [C\ActivityController::class, 'index'])
            ->get('/backups', [C\ActivityController::class, 'backups'])
            ->get('/backups/{file}', [C\ActivityController::class, 'download']);
    }

    /** Loads (or creates) data/config.ini and builds the service container. */
    public function boot(): Services
    {
        if (!is_dir($this->dataDir) && !@mkdir($this->dataDir, 0770, true) && !is_dir($this->dataDir)) {
            throw new \RuntimeException(sprintf('The data directory does not exist and cannot be created: %s', $this->dataDir));
        }
        $configFile = $this->dataDir . '/config.ini';
        if (!is_file($configFile)) {
            $template = (string) file_get_contents($this->appRoot . '/resources/config.tpl.ini');
            if (@file_put_contents($configFile, $template, LOCK_EX) === false) {
                throw new \RuntimeException(sprintf('Cannot create %s. The web server needs write access to the data directory.', $configFile));
            }
            @chmod($configFile, 0640);
        }
        $config = Config::fromString((string) file_get_contents($configFile), $this->appRoot, $this->dataDir);

        return new Services($config, $this->appRoot, $this->dataDir);
    }

    private function resolveAuth(Request $request, Services $services, Session $session): Authorizer
    {
        if (!$services->authEnabled()) {
            return Authorizer::anonymous(false);
        }
        if ($services->config->bool('Common', 'HttpProxyAuth')) {
            // REMOTE_USER is set by the web server itself; the header only counts when it
            // comes from a configured trusted proxy (anyone else could simply send it).
            $proxyUser = $request->server('REMOTE_USER') !== ''
                ? $request->server('REMOTE_USER')
                : ($request->isFromTrustedProxy() ? $request->header('X-Forwarded-User') : '');
            if ($proxyUser !== '' && !preg_match(\SvnManage\Authz\Names::USER_PATTERN, $proxyUser)) {
                error_log('[svnmanage] ignored invalid proxy user name');
                $proxyUser = '';
            }
            if ($proxyUser !== '' && !$this->proxyUserMayLogin($services, $proxyUser)) {
                $proxyUser = '';
            }
            if ($proxyUser !== '' && $session->get('user') !== $proxyUser) {
                if ($services->twoFactorPolicy() !== 'off' && $services->twoFactor()->load()->isEnabled($proxyUser)) {
                    // The proxy vouches for the password; svnmanage still asks for the code.
                    $pending = $session->get('tf_pending');
                    if (!is_array($pending) || ($pending['user'] ?? null) !== $proxyUser || time() - (int) ($pending['at'] ?? 0) > 300) {
                        $session->remove('user');
                        $session->set('tf_pending', ['user' => $proxyUser, 'at' => time(), 'next' => '/', 'proxy' => true]);
                    }
                } else {
                    $session->login($proxyUser);
                    $session->set('via_proxy', true); // the proxy authenticated this user
                }
            }
        }
        $user = $session->get('user');
        if (!is_string($user) || $user === '') {
            return Authorizer::anonymous(true);
        }
        $timeout = $services->config->int('Common', 'SessionTimeout', 3600);
        $maxLifetime = $services->config->int('Common', 'SessionMaxLifetime', 43200);
        $last = (int) $session->get('last_seen', time());
        $loginAt = (float) $session->get('login_at', microtime(true));
        $revoked = $services->revocations()->isRevoked($user, $loginAt);
        if ($revoked || ($timeout > 0 && time() - $last > $timeout) || ($maxLifetime > 0 && time() - $loginAt > $maxLifetime)) {
            $session->remove('user');
            $session->flash('info', 'Your session has expired. Please log in again.');

            return Authorizer::anonymous(true);
        }
        $session->set('last_seen', time());
        try {
            $roles = $services->roles()->load();

            return new Authorizer(true, $user, $roles->rolesOf($user), $roles->managedPaths($user));
        } catch (Throwable) {
            return new Authorizer(true, $user, [], []);
        }
    }

    private function proxyUserMayLogin(Services $services, string $user): bool
    {
        try {
            return \SvnManage\Acl\Acl::allows($services->roles()->load()->rolesOf($user), \SvnManage\Acl\Acl::MOD_BASIC, \SvnManage\Acl\Acl::LOGIN);
        } catch (Throwable $e) {
            error_log('[svnmanage] proxy login refused: ' . $e->getMessage());

            return false;
        }
    }

    private function dispatch(Context $ctx): Response
    {
        $request = $ctx->request;
        $path = $request->path;
        $setupRoute = $path === '/setup' || str_starts_with($path, '/setup/') || $path === '/locale';
        $needsSetup = $ctx->services->needsSetup();
        if ($needsSetup && !$setupRoute) {
            return Response::redirect($ctx->view->url('/setup'), $request);
        }
        if (!$needsSetup && str_starts_with($path, '/setup')) {
            return Response::redirect($ctx->view->url('/'), $request);
        }
        if ($request->isPost()) {
            $token = $request->input('_token') !== '' ? $request->input('_token') : $request->header('X-CSRF-Token');
            $formCookie = (string) $request->cookie(Session::formCookieName($request->isSecure(), $request->basePath));
            $valid = $ctx->session->verifyCsrf($token)
                || (in_array($path, self::STATELESS_FORM_PATHS, true) && $ctx->services->formTokens()->verify($token, $formCookie));
            if (!$valid) {
                throw new HttpException(419, 'Your session has expired or the form is stale. Please reload the page and try again.', 'csrf');
            }
        }
        if ($ctx->auth->username === null && is_array($ctx->session->get('tf_pending'))
            && !in_array($path, ['/login/verify', '/logout', '/locale', '/login'], true)) {
            return Response::redirect($ctx->view->url('/login/verify'), $request);
        }
        if ($this->mustEnrolFirst($ctx, $path)) {
            $ctx->session->flash('info', $ctx->tr->t('Set up two-factor authentication to continue.'));

            return Response::redirect($ctx->view->url('/account/two-factor'), $request);
        }
        $route = $this->router()->match($request->method, $path);
        [$class, $method] = $route['handler'];
        $controller = new $class($ctx);

        return $controller->{$method}(...$route['params']);
    }

    private function httpError(Context $ctx, HttpException $e): Response
    {
        if ($e->status === 401) {
            $next = $ctx->request->method === 'GET' ? $ctx->request->path : '/';

            return Response::redirect($ctx->view->url('/login', ['next' => $next === '/' ? null : $next]), $ctx->request);
        }
        $vars = ['status' => $e->status, 'kind' => $e->kind, 'message' => $e->getMessage()];
        if ($ctx->request->wantsFragment()) {
            return Response::html($ctx->view->render('errors/inline', $vars), $e->status)
                ->withHeader('HX-Retarget', '#toasts')->withHeader('HX-Reswap', 'beforeend');
        }
        $layout = $ctx->auth->isLoggedIn() || !$ctx->auth->authEnabled ? 'layout' : 'layout-auth';
        $title = match ($e->kind) {
            'forbidden' => 'Access denied',
            'not_found' => 'Page not found',
            'inactive' => 'Module not active',
            'csrf' => 'Session expired',
            default => 'Something went wrong',
        };

        return Response::html($ctx->view->page('errors/error', $vars, $ctx->tr->t($title), '', $layout), $e->status);
    }

    private function secure(Response $response, Request $request): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cache-Control' => $response->headers['Cache-Control'] ?? 'no-store',
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function bootError(Throwable $e): string
    {
        error_log('[svnmanage] cannot start: ' . $e->getMessage());

        return '<!doctype html><meta charset="utf-8"><title>svnmanage</title>'
            . '<body style="font-family:system-ui;padding:3rem;color:#0f172a"><h1>svnmanage cannot start</h1>'
            . '<p>The data directory or its configuration is not usable. The reason was written to the PHP error log.</p></body>';
    }
}
