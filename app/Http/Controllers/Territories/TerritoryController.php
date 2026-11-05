<?php

declare(strict_types=1);

namespace App\Http\Controllers\Territories;

use App\Services\Territories\TerritoryService;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class TerritoryController extends Controller
{
    public function __invoke(\Illuminate\Http\Request $request, TerritoryService $service): View
    {
        return view('territories.index', ['countries' => $service->overviewForActor($request->user())]);
    }
}
