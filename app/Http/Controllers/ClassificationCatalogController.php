<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilterRequest;
use App\Http\Requests\ClassificationMetadataRequest;
use App\Http\Requests\ClassificationPreviewRequest;
use App\Services\ClassificationCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClassificationCatalogController extends Controller
{
    public function __construct(private ClassificationCatalogService $catalogs)
    {
    }

    public function index(CatalogFilterRequest $request): View
    {
        return view('catalogs.rules.index', $this->catalogs->index($request->user(), $request->validated()));
    }

    public function show(ClassificationPreviewRequest $request, string $version): View
    {
        return view('catalogs.rules.show', $this->catalogs->show($request->user(), $version, $request->validated()));
    }

    public function edit(Request $request, string $version): View
    {
        return view('catalogs.rules.edit', $this->catalogs->edit($request->user(), $version));
    }

    public function update(ClassificationMetadataRequest $request, string $version): RedirectResponse
    {
        $this->catalogs->update($request->user(), $version, $request->validated());

        return to_route('classification.show', $version)->with('status', 'Metadatos de la versión actualizados.');
    }
}
