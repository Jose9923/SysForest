<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Requests\Reports\ReportRequest;
use App\Services\Reports\ReportReadService;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

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
