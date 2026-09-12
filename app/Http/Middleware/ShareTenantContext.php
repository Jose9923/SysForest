<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TenantContextService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ShareTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $context->clear();
        $organization = null;

        try {
            if ($request->session()->has('active_organization_id')) {
                try {
                    $organization = app(TenantContextService::class)->activate($request->user(), (string) $request->session()->get('active_organization_id'));
                } catch (AuthorizationException) {
                    $request->session()->forget('active_organization_id');
                }
            }
            View::share('activeOrganization', $organization);
            View::share('currentAccessMode', $context->isActive() ? $context->accessMode() : null);

            return $next($request);
        } finally {
            $context->clear();
            View::share('activeOrganization', null);
            View::share('currentAccessMode', null);
        }
    }
}
