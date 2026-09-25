<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicTreeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicTreeController extends Controller
{
    public function __construct(private PublicTreeService $trees)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->trees->listing($request->query())['trees']);
    }

    public function indicators(Request $request): JsonResponse
    {
        return response()->json($this->trees->indicators($request->query()));
    }

    public function show(Request $request, string $organization, string $tree): JsonResponse
    {
        $data = $this->trees->detail($organization, $tree, $request->query());

        return response()->json(['data' => $data['tree'], 'inspections' => $data['inspectionHistory'],
            'maintenances' => $data['maintenanceHistory'], 'evidences' => $data['gallery']]);
    }
}
