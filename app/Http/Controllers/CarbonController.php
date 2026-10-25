<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CarbonProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

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

    public function refresh(Request $request, CarbonProfileService $service): RedirectResponse
    {
        $count = $service->refresh($request->user());

        return redirect()->route('carbon.index')->with('status', 'Estimaciones agregadas: '.$count.'. Se conservaron los resultados anteriores.');
    }
}
