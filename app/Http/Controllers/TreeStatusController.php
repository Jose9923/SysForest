<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTreeStatusRequest;
use App\Services\TreeLifecycleService;
use Illuminate\Http\RedirectResponse;

class TreeStatusController extends Controller
{
    public function __invoke(UpdateTreeStatusRequest $request, string $tree, TreeLifecycleService $service): RedirectResponse
    {
        $record = $service->status($request->user(), $tree, $request->validated());

        return redirect()->route('trees.show', $record->id)->with('status', 'Estado e historial actualizados.');
    }
}
