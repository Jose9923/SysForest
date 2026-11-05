<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\MobileRiskPreviewRequest;
use App\Services\MobileRiskService;
use App\Services\MobileRiskInboxService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MobileRiskController extends Controller
{
    public function index(Request $request, MobileRiskInboxService $service): JsonResponse
    {
        return response()->json($service->index($request->user(), $request->query()));
    }

    public function preview(MobileRiskPreviewRequest $request, MobileRiskService $service): JsonResponse
    {
        return response()->json(['data' => $service->preview($request->user(), $request->validated())]);
    }
}
