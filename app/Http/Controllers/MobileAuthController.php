<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MobileLoginRequest;
use App\Http\Requests\MobileOrganizationsRequest;
use App\Http\Requests\MobileRefreshRequest;
use App\Services\MobileAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileAuthController extends Controller
{
    public function organizations(MobileOrganizationsRequest $request, MobileAuthService $service): JsonResponse
    {
        return response()->json(['data' => $service->organizations(
            $request->validated('email'),
            $request->validated('password'),
        )]);
    }

    public function login(MobileLoginRequest $request, MobileAuthService $service): JsonResponse
    {
        return response()->json($service->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('organization_id'),
        ));
    }

    public function refresh(MobileRefreshRequest $request, MobileAuthService $service): JsonResponse
    {
        return response()->json($service->refresh($request->validated('refresh_token')));
    }

    public function logout(MobileRefreshRequest $request, MobileAuthService $service): Response
    {
        $service->logout($request->validated('refresh_token'));

        return response()->noContent();
    }

    public function me(Request $request, MobileAuthService $service): JsonResponse
    {
        return response()->json($service->profile($request->user(), $request->attributes->get('auth_session')));
    }
}
