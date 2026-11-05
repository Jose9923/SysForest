<?php

declare(strict_types=1);

namespace App\Http\Controllers\Forestry;

use App\Http\Requests\Forestry\CoordinatePreviewRequest;
use App\Services\Forestry\CoordinatePreviewService;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class CoordinatePreviewController extends Controller
{
    public function __invoke(CoordinatePreviewRequest $request, CoordinatePreviewService $service): JsonResponse
    {
        return response()->json($service->preview($request->user(), $request->validated('coordinate_raw')));
    }
}
