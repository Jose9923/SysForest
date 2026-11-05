<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trees;

use App\Http\Requests\Trees\StoreTreeRequest;
use App\Http\Requests\Trees\UpdateTreeRequest;
use App\Http\Requests\Trees\TreeFilterRequest;
use App\Services\Trees\TreeReadService;
use App\Services\Trees\TreeService;
use App\Services\Trees\TreeRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class TreeController extends Controller
{
    public function __construct(private TreeService $trees, private TreeReadService $reader)
    {
    }

    public function index(TreeFilterRequest $request): View
    {
        return view('trees.index', $this->reader->index($request->user(), $request->validated()));
    }

    public function create(Request $request): View
    {
        return view('trees.form', $this->reader->form($request->user()));
    }

    public function store(StoreTreeRequest $request, TreeRegistrationService $registration): RedirectResponse
    {
        $tree = $registration->create($request->user(), $request->validated());

        return redirect()->route('trees.show', $tree->id)->with('status', 'Árbol y primera inspección registrados. Las próximas visitas se agregan desde Inspecciones.');
    }

    public function show(Request $request, string $tree): View
    {
        return view('trees.show', $this->reader->show($request->user(), $tree));
    }

    public function edit(Request $request, string $tree): View
    {
        return view('trees.form', $this->reader->form($request->user(), $tree));
    }

    public function update(UpdateTreeRequest $request, string $tree): RedirectResponse
    {
        $record = $this->trees->update($request->user(), $tree, $request->validated());

        return redirect()->route('trees.show', $record->id)->with('status', 'Ficha actualizada.');
    }
}
