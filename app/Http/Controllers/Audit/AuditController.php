<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Requests\Audit\AuditRequest;
use App\Services\Audit\AuditReadService;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

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
