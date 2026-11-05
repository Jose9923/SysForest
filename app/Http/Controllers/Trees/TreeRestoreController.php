<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trees;

use App\Http\Requests\Trees\RestoreTreeRequest;
use App\Services\Trees\TreeLifecycleService;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;

class TreeRestoreController extends Controller
{
    public function __invoke(RestoreTreeRequest $request, string $tree, TreeLifecycleService $service): RedirectResponse
    {
        $record = $service->restore($request->user(), $tree, $request->validated());

        return redirect()->route('trees.show', $record->id)->with('status', 'Registro restaurado con su identidad original.');
    }
}
