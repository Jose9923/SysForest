<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TaxonomyStoreRequest;
use App\Http\Requests\TaxonomyUpdateRequest;
use App\Services\TaxonomyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaxonomyController extends Controller
{
    public function index(Request $request, string $catalog, TaxonomyService $service): View
    {
        return view('taxonomy.index', $service->index($request->user(), $catalog, $request->query()));
    }

    public function create(Request $request, string $catalog, TaxonomyService $service): View
    {
        return view('taxonomy.form', $service->createForm($request->user(), $catalog));
    }

    public function store(TaxonomyStoreRequest $request, string $catalog, TaxonomyService $service): RedirectResponse
    {
        $service->create($request->user(), $catalog, $request->validated());

        return redirect()->route('taxonomy.index', ['catalog' => $catalog])->with('status', 'Valor nacional creado.');
    }

    public function edit(Request $request, string $catalog, string $record, TaxonomyService $service): View
    {
        return view('taxonomy.form', $service->editForm($request->user(), $catalog, $record));
    }

    public function update(TaxonomyUpdateRequest $request, string $catalog, string $record, TaxonomyService $service): RedirectResponse
    {
        $service->update($request->user(), $catalog, $record, $request->validated());

        return redirect()->route('taxonomy.index', ['catalog' => $catalog])->with('status', 'Catálogo nacional actualizado.');
    }
}
