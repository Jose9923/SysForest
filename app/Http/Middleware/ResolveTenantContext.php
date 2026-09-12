<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TenantContextService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(private TenantContext $context, private TenantContextService $service)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        try {
            // El valor de sesión expresa selección; la membresía en base decide el acceso.
            $this->service->activate($request->user(), (string) $request->session()->get('active_organization_id'));

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
