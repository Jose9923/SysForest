<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileInspectionStoreRequest;
use App\Http\Requests\Mobile\MobileInspectionUpdateRequest;
use App\Http\Requests\Mobile\MobilePageRequest;
use App\Http\Requests\Mobile\MobileInventoryPageRequest;
use App\Services\Mobile\MobileInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileInspectionController extends Controller
{
    public function index(MobilePageRequest $request, string $tree, MobileInspectionService $service): JsonResponse
    {
        return response()->json($service->index($request->user(), $tree, $request->validated()));
    }

    public function inventory(MobileInventoryPageRequest $request, string $inventory, MobileInspectionService $service): JsonResponse
    {
        return response()->json($service->inventory($request->user(), $inventory, $request->validated()));
    }

    public function show(Request $request, string $inspection, MobileInspectionService $service): JsonResponse
    {
        return response()->json($service->show($request->user(), $inspection));
    }

    public function store(MobileInspectionStoreRequest $request, string $tree, MobileInspectionService $service): JsonResponse
    {
        $result = $service->create($request->user(), $tree, $request->validated());

        return response()->json($result, $result['status'] === 'created' ? 201 : 200);
    }

    public function update(MobileInspectionUpdateRequest $request, string $inspection, MobileInspectionService $service): JsonResponse
    {
        return response()->json($service->update($request->user(), $inspection, $request->validated()));
    }
}
