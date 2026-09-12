<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CoordinatePreviewRequest;
use App\Services\CoordinatePreviewService;
use Illuminate\Http\JsonResponse;

class CoordinatePreviewController extends Controller
{
    public function __invoke(CoordinatePreviewRequest $request, CoordinatePreviewService $service): JsonResponse
    {
        return response()->json($service->preview($request->user(), $request->validated('coordinate_raw')));
    }
}
