<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trees;

use App\Http\Requests\Trees\VoidTreeRequest;
use App\Services\Trees\TreeLifecycleService;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;

class TreeVoidController extends Controller
{
    public function __invoke(VoidTreeRequest $request, string $tree, TreeLifecycleService $service): RedirectResponse
    {
        $record = $service->void($request->user(), $tree, $request->validated());

        return redirect()->route('trees.show', $record->id)->with('status', 'Registro anulado. Se conserva la identidad del individuo.');
    }
}
