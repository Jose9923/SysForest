<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LocalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LocalAccessController extends Controller
{
    public function info(): View
    {
        return view('phase3.access');
    }

    public function consume(Request $request, string $token, LocalAccessService $service): RedirectResponse
    {
        $access = $service->consume($token);
        $request->session()->invalidate();
        Auth::login($access['actor']);
        $request->session()->regenerateToken();
        $request->session()->put([
            'active_organization_id' => $access['organization_id'],
            'phase3_local_access' => ['actor_id' => (int) $access['actor']->id, 'expires_at' => $access['expires_at']],
        ]);

        return redirect()->route('organizations.index')->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('phase3.access');
    }
}
