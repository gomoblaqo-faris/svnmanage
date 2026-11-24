<?php

declare(strict_types=1);

namespace SvnManage\Http;

use SvnManage\Acl\Authorizer;
use SvnManage\I18n\Translator;
use SvnManage\Services;
use SvnManage\View\View;

/** Everything a controller needs for one request. */
final class Context
{
    public function __construct(
        public readonly Request $request,
        public readonly Services $services,
        public readonly Session $session,
        public readonly Authorizer $auth,
        public readonly Translator $tr,
        public readonly View $view,
    ) {
    }
}
