<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LogoutService;
use App\Services\WebSessionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
