<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileTreePhotoRequest;
use App\Http\Requests\Mobile\MobileInventoryPageRequest;
use App\Services\Mobile\MobileTreePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MobileTreePhotoController extends Controller
{
    public function __construct(private MobileTreePhotoService $photos)
    {
    }

    public function index(Request $request, string $tree): JsonResponse
    {
        return response()->json($this->photos->index($request->user(), $tree, $request->query()));
    }

    public function inventory(MobileInventoryPageRequest $request, string $inventory): JsonResponse
    {
        return response()->json($this->photos->inventory($request->user(), $inventory, $request->validated()));
    }

    public function store(MobileTreePhotoRequest $request, string $tree): JsonResponse
    {
        $result = $this->photos->upload($request->user(), $tree, $request->validated());

        return response()->json(['data' => $result], $result['status'] === 'created' ? 201 : 200);
    }

    public function content(Request $request, string $photo): StreamedResponse
    {
        return $this->photos->content($request->user(), $photo);
    }
}
