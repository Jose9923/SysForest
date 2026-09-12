<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SystemStatusService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class SystemStatusController extends Controller
{
    public function __construct(private readonly SystemStatusService $service)
    {
    }

    public function index(): View
    {
        return view('system-status', ['status' => $this->service->status()]);
    }

    public function readiness(): JsonResponse
    {
        $ready = $this->service->status()->isReady();

        return response()->json(
            ['status' => $ready ? 'ok' : 'error'],
            $ready ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }
}
