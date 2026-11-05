<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\MobileMaintenanceRequest;
use App\Services\MobileMaintenanceService;
use Illuminate\Http\JsonResponse;

class MobileMaintenanceController extends Controller
{
    public function index(MobileMaintenanceRequest $request, string $inventory, MobileMaintenanceService $service): JsonResponse
    {
        return response()->json($service->index($request->user(), $inventory, $request->validated()));
    }
}
