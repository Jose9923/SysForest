<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTreeLocationRequest;
use App\Services\TreeLifecycleService;
use Illuminate\Http\RedirectResponse;

class TreeLocationController extends Controller
{
    public function __invoke(UpdateTreeLocationRequest $request, string $tree, TreeLifecycleService $service): RedirectResponse
    {
        $record = $service->location($request->user(), $tree, $request->validated());

        return redirect()->route('trees.show', $record->id)->with('status', 'Ubicación e historial actualizados.');
    }
}
