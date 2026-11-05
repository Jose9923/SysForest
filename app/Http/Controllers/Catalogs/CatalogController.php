<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalogs;

use App\Http\Requests\Catalogs\CatalogEntryRequest;
use App\Http\Requests\Catalogs\CatalogFilterRequest;
use App\Services\Catalogs\CatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class CatalogController extends Controller
{
    public function __construct(private CatalogService $catalogs)
    {
    }

    public function dashboard(Request $request): View
    {
        return view('catalogs.dashboard', $this->catalogs->dashboard($request->user()));
    }

    public function index(CatalogFilterRequest $request, string $catalog): View
    {
        return view('catalogs.index', $this->catalogs->index($request->user(), $catalog, $request->validated()));
    }

    public function create(Request $request, string $catalog): View
    {
        return view('catalogs.form', $this->catalogs->form($request->user(), $catalog));
    }

    public function store(CatalogEntryRequest $request, string $catalog): RedirectResponse
    {
        $this->catalogs->create($request->user(), $catalog, $request->validated());

        return to_route('catalogs.index', $catalog)->with('status', 'Valor nacional creado.');
    }

    public function edit(Request $request, string $catalog, string $entry): View
    {
        return view('catalogs.form', $this->catalogs->form($request->user(), $catalog, $entry));
    }

    public function update(CatalogEntryRequest $request, string $catalog, string $entry): RedirectResponse
    {
        $this->catalogs->update($request->user(), $catalog, $entry, $request->validated());

        return to_route('catalogs.index', $catalog)->with('status', 'Valor nacional actualizado.');
    }
}
