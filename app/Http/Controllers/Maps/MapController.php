<?php

declare(strict_types=1);

namespace App\Http\Controllers\Maps;

use App\Services\Maps\MapReadService;
use App\Services\Maps\MapTreeNavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class MapController extends Controller
{
    public function __construct(private MapReadService $maps, private MapTreeNavigationService $navigation)
    {
    }

    public function index(Request $request): View
    {
        return view('maps.index', $this->maps->page($request->user(), $request->query()));
    }

    public function trees(Request $request): JsonResponse
    {
        return $this->json($this->maps->points($request->user(), $request->query()));
    }

    public function clusters(Request $request): JsonResponse
    {
        return $this->json($this->maps->clusters($request->user(), $request->query()));
    }

    public function heat(Request $request): JsonResponse
    {
        return $this->json($this->maps->heat($request->user(), $request->query()));
    }

    public function extent(Request $request): JsonResponse
    {
        return $this->json($this->maps->extent($request->user(), $request->query()));
    }

    public function search(Request $request): JsonResponse
    {
        return $this->json($this->maps->search($request->user(), $request->query()));
    }

    public function options(Request $request, string $kind): JsonResponse
    {
        return $this->json($this->maps->options($request->user(), $kind, $request->query()));
    }

    public function open(Request $request, string $tree): RedirectResponse
    {
        $record = $this->navigation->open($request->user(), $tree, $request->only('scope'));
        $request->session()->put('active_organization_id', $record->organization_id);

        return redirect()->route('trees.show', $record->id);
    }

    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}
