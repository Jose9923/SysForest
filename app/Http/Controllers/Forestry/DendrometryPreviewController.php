<?php

declare(strict_types=1);

namespace App\Http\Controllers\Forestry;

use App\Http\Requests\Forestry\DendrometryPreviewRequest;
use App\Services\Forestry\DendrometryPreviewService;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class DendrometryPreviewController extends Controller
{
    public function __invoke(DendrometryPreviewRequest $request, DendrometryPreviewService $preview): JsonResponse
    {
        return response()->json($preview->preview($request->user(), $request->validated()));
    }
}
