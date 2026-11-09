<?php

declare(strict_types=1);

namespace App\Http\Controllers\Maps;

use App\Http\Controllers\Controller;
use App\Services\Maps\MapTreePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MapTreePhotoController extends Controller
{
    public function __construct(private MapTreePhotoService $photos)
    {
    }

    public function show(Request $request, string $tree): JsonResponse
    {
        return response()->json($this->photos->metadata($request->user(), $tree, $request->query()))
            ->header('Cache-Control', 'private, no-store');
    }

    public function content(Request $request, string $tree, string $evidence): StreamedResponse
    {
        return $this->photos->content($request->user(), $tree, $evidence, $request->query());
    }
}
