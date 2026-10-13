<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AuditRequest;
use App\Services\AuditExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuditExportController extends Controller
{
    public function __construct(private AuditExportService $exports)
    {
    }

    public function __invoke(AuditRequest $request): BinaryFileResponse
    {
        $artifact = $this->exports->forUser($request->user(), $request->filters());

        return response()->download($artifact->path, $artifact->filename, [
            'Content-Type' => $artifact->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Export-Row-Count' => (string) $artifact->rows,
        ])->setPrivate()->deleteFileAfterSend(true);
    }
}
