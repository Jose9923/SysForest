<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AnalyticsReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function __construct(private AnalyticsReadService $analytics)
    {
    }

    public function index(Request $request): View
    {
        return view('analytics.index', $this->analytics->page($request->user(), $request->query()));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->analytics->page($request->user(), $request->query()))
            ->header('Cache-Control', 'private, no-store');
    }
}
