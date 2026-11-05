<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobilePageRequest;
use App\Http\Requests\Mobile\MobileTreeUpdateRequest;
use App\Services\Mobile\MobileTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileTreeController extends Controller
{
    public function index(MobilePageRequest $request, string $inventory, MobileTreeService $service): JsonResponse
    {
        return response()->json($service->index($request->user(), $inventory, $request->validated()));
    }

    public function show(Request $request, string $tree, MobileTreeService $service): JsonResponse
    {
        return response()->json($service->show($request->user(), $tree));
    }

    public function update(MobileTreeUpdateRequest $request, string $tree, MobileTreeService $service): JsonResponse
    {
        return response()->json($service->update($request->user(), $tree, $request->validated()));
    }
}
