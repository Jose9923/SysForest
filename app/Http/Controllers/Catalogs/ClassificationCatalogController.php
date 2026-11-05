<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalogs;

use App\Http\Requests\Catalogs\CatalogFilterRequest;
use App\Http\Requests\Catalogs\ClassificationMetadataRequest;
use App\Http\Requests\Catalogs\ClassificationPreviewRequest;
use App\Services\Catalogs\ClassificationCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

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
