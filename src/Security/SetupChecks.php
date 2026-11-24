<?php

declare(strict_types=1);

namespace SvnManage\Security;

use SvnManage\Http\Request;
use SvnManage\Services;

/**
 * What svnmanage can tell about this server, shown on the wizard's security step so the
 * choices there are made with the facts in view.
 */
final class SetupChecks
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const INFO = 'info';

    /**
     * @param Request $request with the trusted proxies already applied
     * @return list<array{0: string, 1: string, 2: list<string>}> status, translatable message, arguments
     */
    public static function run(Request $request, Services $services): array
    {
        $checks = [self::connection($request)];
        $checks[] = [self::INFO, 'svnmanage sees your address as %0. If you restrict Allowed networks, include it.', [$request->ip()]];
        $checks[] = $services->authEnabled()
            ? [self::OK, 'Login is required.', []]
            : [self::WARN, 'Login is turned off, so everyone is an administrator. That is only acceptable on a private, trusted network.', []];
        if (filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || ini_get('display_errors') === 'stderr') {
            $checks[] = [self::WARN, 'PHP shows error details to visitors (display_errors). Turn it off on a public server.', []];
        }
        $exposure = ExposureCheck::run($services->managedPaths(), [$request->server('DOCUMENT_ROOT'), $services->appRoot . '/public']);
        foreach ($exposure as [$message, $args]) {
            $checks[] = [self::WARN, $message, $args];
        }
        if ($exposure === []) {
            $checks[] = [self::OK, 'No managed file is inside the web server’s document root or open to every account on this server.', []];
        }

        return $checks;
    }

    /** @return array{0: string, 1: string, 2: list<string>} */
    private static function connection(Request $request): array
    {
        if ($request->isSecure()) {
            return [self::OK, 'This page was opened over HTTPS.', []];
        }
        if ($request->claimsHttps()) {
            return [self::WARN, 'A proxy reports HTTPS, but svnmanage does not trust it, so this page counts as plain HTTP. Have the web server set the HTTPS variable for requests from the proxy or tunnel, or list the proxy under Trusted reverse proxies.', []];
        }

        return [self::WARN, 'This page was opened over plain HTTP. Put svnmanage behind HTTPS before it faces the internet; Require HTTPS can only be turned on from an HTTPS page.', []];
    }
}
