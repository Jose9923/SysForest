<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OptionalJwtCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        return app(AuthenticateJwtCookie::class)->handle($request, $next, optional: true);
    }
}
