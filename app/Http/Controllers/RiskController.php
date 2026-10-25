<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RiskMethodologyService;
use App\Services\RiskEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Coordina configuración y vista previa; las reglas residen en Services. */
class RiskController extends Controller
{
    public function index(Request $request, RiskMethodologyService $service): View
    {
        return view('risk.index', $service->page($request->user()));
    }

    public function store(Request $request, RiskMethodologyService $service): RedirectResponse
    {
        $service->publish($request->user(), $request->all());

        return redirect()->route('risk.index')->with('status', 'Nueva versión institucional publicada. Las evaluaciones anteriores se conservan.');
    }

    public function preview(Request $request, string $tree, RiskEvaluationService $service): JsonResponse
    {
        $data = $request->validate(['phytosanitary_condition_ids' => ['sometimes', 'array', 'max:100'],
            'phytosanitary_condition_ids.*' => ['uuid', 'distinct'], 'inspection_id' => ['nullable', 'uuid']]);

        return response()->json($service->preview($request->user(), $tree, $data));
    }
}
