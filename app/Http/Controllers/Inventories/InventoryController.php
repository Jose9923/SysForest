<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventories;

use App\Http\Requests\Inventories\StoreInventoryRequest;
use App\Http\Requests\Inventories\UpdateInventoryRequest;
use App\Services\Inventories\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventories)
    {
    }

    public function index(Request $request): View
    {
        return view('inventories.index', $this->inventories->index($request->user(), $request->only(['q', 'status', 'active', 'page'])));
    }

    public function create(Request $request): View
    {
        return view('inventories.form', $this->inventories->form($request->user()));
    }

    public function store(StoreInventoryRequest $request): RedirectResponse
    {
        $inventory = $this->inventories->create($request->user(), $request->validated());

        return redirect()->route('inventories.show', $inventory->id)->with('status', 'Inventario creado.');
    }

    public function show(Request $request, string $inventory): View
    {
        return view('inventories.show', $this->inventories->show($request->user(), $inventory));
    }

    public function edit(Request $request, string $inventory): View
    {
        return view('inventories.form', $this->inventories->form($request->user(), $inventory));
    }

    public function update(UpdateInventoryRequest $request, string $inventory): RedirectResponse
    {
        $record = $this->inventories->update($request->user(), $inventory, $request->validated());

        return redirect()->route('inventories.show', $record->id)->with('status', 'Inventario actualizado.');
    }
}
