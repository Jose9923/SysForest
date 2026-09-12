<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTreeRequest;
use App\Http\Requests\UpdateTreeRequest;
use App\Http\Requests\TreeFilterRequest;
use App\Services\TreeReadService;
use App\Services\TreeService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

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

    public function store(StoreTreeRequest $request): RedirectResponse
    {
        $tree = $this->trees->create($request->user(), $request->validated());

        return redirect()->route('trees.show', $tree->id)->with('status', 'Individuo forestal creado.');
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
