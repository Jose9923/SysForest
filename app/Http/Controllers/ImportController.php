<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CancelImportRequest;
use App\Http\Requests\ConfirmImportRequest;
use App\Http\Requests\StoreImportRequest;
use App\Services\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Coordina la revisión y la confirmación explícita de lotes de la organización activa. */
class ImportController extends Controller
{
    public function __construct(private ImportService $imports)
    {
    }

    public function index(Request $request): View
    {
        return view('imports.index', $this->imports->index($request->user()));
    }

    public function create(Request $request): View
    {
        return view('imports.create', $this->imports->form($request->user()));
    }

    public function template(Request $request): BinaryFileResponse
    {
        return $this->imports->downloadTemplate($request->user());
    }

    public function store(StoreImportRequest $request): RedirectResponse
    {
        $batch = $this->imports->upload($request->user(), $request->file('file'), $request->validated('inventory_id'));

        return redirect()->route('imports.show', $batch->id)
            ->with('status', 'Archivo revisado. Consulta el resultado antes de confirmar la importación.');
    }

    public function show(Request $request, string $batch): View
    {
        return view('imports.show', $this->imports->show($request->user(), $batch));
    }

    public function confirm(ConfirmImportRequest $request, string $batch): RedirectResponse
    {
        $record = $this->imports->confirm($request->user(), $batch, (int) $request->validated('lock_version'));

        return redirect()->route('imports.show', $record->id)->with('status', 'Lote procesado. Consulta el resultado de la importación.');
    }

    public function cancel(CancelImportRequest $request, string $batch): RedirectResponse
    {
        $record = $this->imports->cancel($request->user(), $batch, (int) $request->validated('lock_version'));

        return redirect()->route('imports.show', $record->id)->with('status', 'Lote cancelado. Se conserva su revisión para auditoría.');
    }
}
