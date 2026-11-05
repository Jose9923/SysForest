<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileReferenceRequest;
use App\Services\Mobile\MobileReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileReferenceController extends Controller
{
    public function __construct(private MobileReferenceService $references)
    {
    }

    public function manifest(Request $request): JsonResponse
    {
        return response()->json($this->references->manifest($request->user()));
    }

    public function catalog(MobileReferenceRequest $request, string $catalog): JsonResponse
    {
        return response()->json($this->references->catalog($request->user(), $catalog, $request->validated()));
    }

    public function inventories(MobileReferenceRequest $request): JsonResponse
    {
        return response()->json($this->references->inventories($request->user(), $request->validated()));
    }
}
