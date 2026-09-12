<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DendrometryPreviewRequest;
use App\Services\DendrometryPreviewService;
use Illuminate\Http\JsonResponse;

class DendrometryPreviewController extends Controller
{
    public function __invoke(DendrometryPreviewRequest $request, DendrometryPreviewService $preview): JsonResponse
    {
        return response()->json($preview->preview($request->user(), $request->validated()));
    }
}
