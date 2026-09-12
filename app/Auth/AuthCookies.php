<?php

declare(strict_types=1);

namespace App\Auth;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class AuthCookies
{
    public function attach(Response $response, TokenPair $pair): Response
    {
        $accessExpires = min(
            now()->addMinutes((int) config('security.access_ttl_minutes'))->getTimestamp(),
            $pair->session->expires_at->getTimestamp(),
        );
        $response->headers->setCookie($this->cookie((string) config('security.access_cookie'), $pair->accessToken, $accessExpires));
        $response->headers->setCookie($this->cookie((string) config('security.refresh_cookie'), $pair->refreshToken, $pair->session->expires_at->getTimestamp()));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function clear(Response $response): Response
    {
        foreach (['access_cookie', 'refresh_cookie'] as $key) {
            $response->headers->setCookie($this->cookie((string) config('security.'.$key), '', 1));
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function cookie(string $name, #[\SensitiveParameter] string $value, int $expires): Cookie
    {
        $localHttp = app()->environment('local') && parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'http'
            && ! request()->isSecure();

        return new Cookie($name, $value, $expires, '/', null, ! $localHttp, true, false, Cookie::SAMESITE_LAX);
    }
}
