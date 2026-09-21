<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MaintenanceFilterRequest;
use App\Http\Requests\RestoreMaintenanceRequest;
use App\Http\Requests\StoreMaintenanceRequest;
use App\Http\Requests\TransitionMaintenanceRequest;
use App\Http\Requests\UpdateMaintenanceRequest;
use App\Http\Requests\VoidMaintenanceRequest;
use App\Services\MaintenanceReadService;
use App\Services\MaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaintenanceController extends Controller
{
    public function __construct(private MaintenanceReadService $reader, private MaintenanceService $maintenances)
    {
    }

    public function index(MaintenanceFilterRequest $request): View
    {
        return view('maintenances.index', $this->reader->index($request->user(), $request->validated()));
    }

    public function create(Request $request, string $tree): View
    {
        return view('maintenances.form', $this->reader->form($request->user(), $tree));
    }

    public function store(StoreMaintenanceRequest $request, string $tree): RedirectResponse
    {
        $record = $this->maintenances->create($request->user(), $tree, $request->validated());

        return redirect()->route('maintenances.show', $record->id)->with('status', 'Mantenimiento registrado en estado Pendiente.');
    }

    public function show(Request $request, string $maintenance): View
    {
        return view('maintenances.show', $this->reader->show($request->user(), $maintenance));
    }

    public function edit(Request $request, string $maintenance): View
    {
        return view('maintenances.form', $this->reader->edit($request->user(), $maintenance));
    }

    public function update(UpdateMaintenanceRequest $request, string $maintenance): RedirectResponse
    {
        $record = $this->maintenances->update($request->user(), $maintenance, $request->validated());

        return redirect()->route('maintenances.show', $record->id)->with('status', 'Datos del mantenimiento actualizados.');
    }

    public function transition(TransitionMaintenanceRequest $request, string $maintenance): RedirectResponse
    {
        $record = $this->maintenances->transition($request->user(), $maintenance, $request->validated());

        return redirect()->route('maintenances.show', $record->id)->with('status', 'Estado del mantenimiento actualizado.');
    }

    public function destroy(VoidMaintenanceRequest $request, string $maintenance): RedirectResponse
    {
        $record = $this->maintenances->void($request->user(), $maintenance, $request->validated());

        return redirect()->route('maintenances.show', $record->id)->with('status', 'Registro anulado. Su historial y evidencias se conservan.');
    }

    public function restore(RestoreMaintenanceRequest $request, string $maintenance): RedirectResponse
    {
        $record = $this->maintenances->restore($request->user(), $maintenance, $request->validated());

        return redirect()->route('maintenances.show', $record->id)->with('status', 'Mantenimiento restaurado con su misma identidad.');
    }
}
