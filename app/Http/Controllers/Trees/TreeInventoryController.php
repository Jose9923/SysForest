<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trees;

use App\Http\Requests\Trees\IncludeTreeInventoryRequest;
use App\Services\Trees\TreeService;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;

class TreeInventoryController extends Controller
{
    public function __invoke(IncludeTreeInventoryRequest $request, string $tree, TreeService $service): RedirectResponse
    {
        $record = $service->include($request->user(), $tree, $request->validated());

        return redirect()->route('trees.show', $record->id)->with('status', 'Participación en inventarios guardada.');
    }
}
