<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TerritoryService;
use Illuminate\View\View;

class TerritoryController extends Controller
{
    public function __invoke(\Illuminate\Http\Request $request, TerritoryService $service): View
    {
        return view('territories.index', ['countries' => $service->overviewForActor($request->user())]);
    }
}
