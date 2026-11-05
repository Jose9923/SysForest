<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inspections;

use App\Http\Requests\Inspections\StoreTreeInspectionRequest;
use App\Http\Requests\Inspections\UpdateTreeInspectionRequest;
use App\Http\Requests\Inspections\TreeInspectionFilterRequest;
use App\Services\Inspections\TreeInspectionReadService;
use App\Services\Inspections\TreeInspectionService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class TreeInspectionController extends Controller
{
    public function __construct(private TreeInspectionReadService $reader, private TreeInspectionService $inspections)
    {
    }

    public function index(TreeInspectionFilterRequest $request): View
    {
        return view('inspections.index', $this->reader->index($request->user(), $request->validated()));
    }

    public function create(Request $request, string $tree): View
    {
        return view('inspections.form', $this->reader->form($request->user(), $tree));
    }

    public function store(StoreTreeInspectionRequest $request, string $tree): RedirectResponse
    {
        $inspection = $this->inspections->create($request->user(), $tree, $request->validated());

        return redirect()->route('inspections.show', $inspection->id)->with('status', 'Inspección registrada. Las visitas anteriores se conservan.');
    }

    public function show(Request $request, string $inspection): View
    {
        return view('inspections.show', $this->reader->show($request->user(), $inspection));
    }

    public function edit(Request $request, string $inspection): View
    {
        return view('inspections.form', $this->reader->edit($request->user(), $inspection));
    }

    public function update(UpdateTreeInspectionRequest $request, string $inspection): RedirectResponse
    {
        $record = $this->inspections->update($request->user(), $inspection, $request->validated());

        return redirect()->route('inspections.show', $record->id)->with('status', 'Corrección guardada con su revisión de auditoría.');
    }
}
