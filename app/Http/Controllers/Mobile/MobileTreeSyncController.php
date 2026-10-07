<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\MobileTreeSyncRequest;
use App\Services\MobileTreeSyncService;
use Illuminate\Http\JsonResponse;

class MobileTreeSyncController extends Controller
{
    public function store(MobileTreeSyncRequest $request, string $inventory, MobileTreeSyncService $service): JsonResponse
    {
        return response()->json($service->sync($request->user(), $inventory, $request->validated()['items']));
    }
}
