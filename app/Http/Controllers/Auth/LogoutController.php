<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\Auth\LogoutService;
use App\Services\Auth\WebSessionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\Controller;

class LogoutController extends Controller
{
    public function __invoke(Request $request, LogoutService $logout, WebSessionService $web): Response
    {
        $logout->logout(
            (string) $request->cookie(config('security.access_cookie')),
            (string) $request->cookie(config('security.refresh_cookie')),
        );

        return $web->end($request);
    }
}
