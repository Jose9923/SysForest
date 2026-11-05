<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\MobileInventoryStoreRequest;
use App\Services\MobileInventoryService;
use Illuminate\Http\JsonResponse;

class MobileInventoryController extends Controller
{
    public function store(MobileInventoryStoreRequest $request, MobileInventoryService $service): JsonResponse
    {
        $result = $service->create($request->user(), $request->validated());

        return response()->json($result, $result['status'] === 'created' ? 201 : 200);
    }
}
