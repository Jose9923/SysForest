<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicMapController extends Controller
{
    public function __construct(private PublicMapService $map)
    {
    }

    public function trees(Request $request): JsonResponse
    {
        return response()->json($this->map->points($request->query()));
    }

    public function clusters(Request $request): JsonResponse
    {
        return response()->json($this->map->clusters($request->query()));
    }

    public function heat(Request $request): JsonResponse
    {
        return response()->json($this->map->heat($request->query()));
    }

    public function extent(Request $request): JsonResponse
    {
        return response()->json($this->map->extent($request->query()));
    }

    public function search(Request $request): JsonResponse
    {
        return response()->json($this->map->search($request->query()));
    }

    public function options(Request $request, string $kind): JsonResponse
    {
        return response()->json($this->map->options($kind, $request->query()));
    }
}
