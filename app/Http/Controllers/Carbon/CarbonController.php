<?php

declare(strict_types=1);

namespace App\Http\Controllers\Carbon;

use App\Services\Carbon\CarbonProfileService;
use App\Services\Carbon\CarbonRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class CarbonController extends Controller
{
    public function index(Request $request, CarbonProfileService $service): View
    {
        return view('carbon.index', $service->page($request->user()));
    }

    public function store(Request $request, CarbonProfileService $service): RedirectResponse
    {
        $service->publish($request->user(), $request->all());

        return redirect()->route('carbon.index')->with('status', 'Referencia publicada. Las nuevas visitas la usarán automáticamente. Puedes actualizar las estimaciones existentes conservando su historia.');
    }

    public function progress(Request $request, CarbonRefreshService $service): JsonResponse
    {
        return response()->json(['refresh' => $service->latest($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function refresh(Request $request, CarbonProfileService $service): RedirectResponse
    {
        $service->refresh($request->user());

        return redirect()->route('carbon.index')->with('status', 'Actualización solicitada. Puedes consultar su progreso y continuar trabajando; los resultados anteriores se conservan.');
    }
}
