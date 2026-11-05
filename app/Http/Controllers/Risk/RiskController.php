<?php

declare(strict_types=1);

namespace App\Http\Controllers\Risk;

use App\Exceptions\Inspections\StaleInspectionException;
use App\Services\Risk\RiskDecisionService;
use App\Services\Risk\RiskMethodologyService;
use App\Services\Risk\RiskTreeReadService;
use App\Services\Risk\RiskEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

/** Coordina consulta, configuración y vista previa; las reglas residen en Services. */
class RiskController extends Controller
{
    public function index(Request $request, RiskTreeReadService $service): View
    {
        return view('risk.index', $service->page($request->user(), $request->query()));
    }

    public function decision(Request $request, string $tree, RiskDecisionService $service): View
    {
        return view('risk.decision', $service->page($request->user(), $tree));
    }

    public function updateDecision(Request $request, string $tree, RiskDecisionService $service): RedirectResponse|Response
    {
        try {
            $service->update($request->user(), $tree, $request->all());
        } catch (StaleInspectionException|ConflictHttpException $exception) {
            return response()->view('risk.decision-conflict', ['treeId' => $tree,
                'conflictMessage' => $exception instanceof StaleInspectionException
                    ? 'Esta inspección cambió desde que abriste la decisión. Revisa la versión actual antes de guardar.'
                    : $exception->getMessage()], 409);
        }

        return redirect()->route('risk.index')->with('status', 'Riesgo actualizado sobre la última inspección. La decisión anterior se conserva en el historial.');
    }

    public function methodology(Request $request, RiskMethodologyService $service): View
    {
        return view('risk.methodology', $service->page($request->user()));
    }

    public function store(Request $request, RiskMethodologyService $service): RedirectResponse
    {
        $service->publish($request->user(), $request->all());

        return redirect()->route('risk.methodology')->with('status', 'Nueva versión institucional publicada. Las evaluaciones anteriores se conservan.');
    }

    public function previewRegistration(Request $request, RiskEvaluationService $service): JsonResponse
    {
        $data = $request->validate(['phytosanitary_condition_ids' => ['sometimes', 'array', 'max:100'],
            'phytosanitary_condition_ids.*' => ['uuid', 'distinct'], 'inspection_id' => ['prohibited']]);

        return response()->json($service->previewRegistration($request->user(), $data));
    }

    public function preview(Request $request, string $tree, RiskEvaluationService $service): JsonResponse
    {
        $data = $request->validate(['phytosanitary_condition_ids' => ['sometimes', 'array', 'max:100'],
            'phytosanitary_condition_ids.*' => ['uuid', 'distinct'], 'inspection_id' => ['nullable', 'uuid']]);

        return response()->json($service->preview($request->user(), $tree, $data));
    }
}
