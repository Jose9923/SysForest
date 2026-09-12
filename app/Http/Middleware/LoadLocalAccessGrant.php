<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\LocalAccessContext;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoadLocalAccessGrant
{
    public function __construct(private LocalAccessContext $access, private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->access->clear();
        $this->tenant->clear();

        try {
            $grant = $request->session()->get('phase3_local_access', []);

            if ($grant !== [] && (!app()->environment('local') || (int) ($grant['expires_at'] ?? 0) <= time())) {
                \Illuminate\Support\Facades\Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('phase3.access')->with('status', 'El acceso técnico ha caducado.');
            }

            if (app()->environment('local') && $request->user()
                && (int) ($grant['actor_id'] ?? 0) === (int) $request->user()->id
                && (int) ($grant['expires_at'] ?? 0) > time()) {
                $this->access->grant((int) $grant['actor_id'], (int) $grant['expires_at']);
            }

            return $next($request);
        } finally {
            $this->access->clear();
            $this->tenant->clear();
        }
    }
}
