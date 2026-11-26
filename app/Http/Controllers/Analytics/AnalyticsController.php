<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Services\Analytics\AnalyticsReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Controllers\Controller;
use App\Services\Maps\MapScopeSelectionService;

class AnalyticsController extends Controller
{
    public function __construct(private AnalyticsReadService $analytics, private MapScopeSelectionService $scopes)
    {
    }

    public function index(Request $request): View
    {
        if ($selection = $this->scopes->selectionPage($request->user(), $request->query(), 'analytics.index')) {
            return view('maps.select-organization', $selection);
        }

        return view('analytics.index', $this->analytics->page($request->user(), $request->query()));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->analytics->page($request->user(), $request->query()))
            ->header('Cache-Control', 'private, no-store');
    }
}
