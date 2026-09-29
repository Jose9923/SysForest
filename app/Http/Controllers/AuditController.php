<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AuditRequest;
use App\Services\AuditReadService;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __construct(private AuditReadService $audit)
    {
    }

    public function index(AuditRequest $request): View
    {
        return view('audit.index', $this->audit->index($request->user(), $request->filters()));
    }

    public function show(AuditRequest $request, string $source, string $entry): View
    {
        return view('audit.show', $this->audit->show($request->user(), $source, $entry, $request->filters()));
    }
}
