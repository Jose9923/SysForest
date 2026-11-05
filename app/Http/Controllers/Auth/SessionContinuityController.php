<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\AuthCookies;
use App\Http\Requests\Auth\SessionContinuityRequest;
use App\Services\Auth\SessionContinuityService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\Controller;

class SessionContinuityController extends Controller
{
    public function status(Request $request, SessionContinuityService $sessions): Response
    {
        return response()->json($sessions->status($request));
    }

    public function ensure(SessionContinuityRequest $request, SessionContinuityService $sessions, AuthCookies $cookies): Response
    {
        $result = $sessions->ensure($request);
        $response = response()->json($result['status']);

        return $result['pair'] === null ? $response : $cookies->attach($response, $result['pair']);
    }
}
