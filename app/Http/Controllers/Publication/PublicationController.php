<?php

declare(strict_types=1);

namespace App\Http\Controllers\Publication;

use App\Services\Publication\PublicationReadService;
use App\Services\Publication\PublicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class PublicationController extends Controller
{
    public function __construct(private PublicationService $writer, private PublicationReadService $reader)
    {
    }

    public function index(Request $request): View
    {
        return view('publication.index', $this->reader->page($request->user(), $request->query()));
    }

    public function organization(Request $request): RedirectResponse
    {
        $this->writer->organization($request->user(), $request->all());

        return $this->back('organizations');
    }

    public function inventory(Request $request, string $inventory): RedirectResponse
    {
        $this->writer->inventory($request->user(), $inventory, $request->all());

        return $this->back('inventories');
    }

    public function tree(Request $request, string $tree): RedirectResponse
    {
        $this->writer->tree($request->user(), $tree, $request->all());

        return $this->back('trees');
    }

    public function inspection(Request $request, string $inspection): RedirectResponse
    {
        $this->writer->inspection($request->user(), $inspection, $request->all());

        return $this->back('inspections');
    }

    public function evidence(Request $request, string $evidence): RedirectResponse
    {
        $this->writer->evidence($request->user(), $evidence, $request->all());

        return $this->back('evidences');
    }

    private function back(string $kind): RedirectResponse
    {
        return redirect()->route('publication.index', ['kind' => $kind])
            ->with('status', 'Autorización de publicación guardada. La visibilidad depende también de su organización, campaña y propietario.');
    }
}
