<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConditionCatalogStoreRequest;
use App\Http\Requests\ConditionCatalogUpdateRequest;
use App\Services\ConditionCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConditionCatalogController extends Controller
{
    public function index(Request $request, string $catalog, ConditionCatalogService $service): View
    {
        return view('conditions.index', $service->index($request->user(), $catalog, $request->query()));
    }

    public function create(Request $request, string $catalog, ConditionCatalogService $service): View
    {
        return view('conditions.form', $service->createForm($request->user(), $catalog));
    }

    public function store(ConditionCatalogStoreRequest $request, string $catalog, ConditionCatalogService $service): RedirectResponse
    {
        $service->create($request->user(), $catalog, $request->validated());

        return redirect()->route('conditions.index', ['catalog' => $catalog])->with('status', 'Valor nacional creado.');
    }

    public function edit(Request $request, string $catalog, string $record, ConditionCatalogService $service): View
    {
        return view('conditions.form', $service->editForm($request->user(), $catalog, $record));
    }

    public function update(ConditionCatalogUpdateRequest $request, string $catalog, string $record, ConditionCatalogService $service): RedirectResponse
    {
        $service->update($request->user(), $catalog, $record, $request->validated());

        return redirect()->route('conditions.index', ['catalog' => $catalog])->with('status', 'Catálogo nacional actualizado.');
    }
}
