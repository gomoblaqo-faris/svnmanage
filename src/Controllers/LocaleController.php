<?php

declare(strict_types=1);

namespace SvnManage\Controllers;

use SvnManage\Http\Response;
use SvnManage\I18n\Translator;

final class LocaleController extends Controller
{
    public function switch(): Response
    {
        $locale = $this->request->input('locale');
        if (!isset(Translator::LOCALES[$locale])) {
            $locale = 'en_US';
        }
        $cookie = sprintf(
            'locale=%s; Max-Age=31536000; Path=%s; HttpOnly; SameSite=Lax%s',
            $locale,
            $this->request->basePath === '' ? '/' : $this->request->basePath . '/',
            $this->request->isSecure() ? '; Secure' : '',
        );

        return $this->back('/')->withHeader('Set-Cookie', $cookie);
    }
}
