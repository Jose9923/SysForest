<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Services\ReportReadService;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __construct(private ReportReadService $reports)
    {
    }

    public function index(ReportRequest $request): View
    {
        return view('reports.index', $this->reports->forUser($request->user(), $request->query()));
    }

    public function publicIndex(ReportRequest $request): View
    {
        return view('reports.index', $this->reports->forPublic($request->query()));
    }
}
