<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\ExportArtifact;
use App\Http\Requests\ReportRequest;
use App\Services\ExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function __construct(private ExportService $exports)
    {
    }

    public function download(ReportRequest $request, string $format): BinaryFileResponse
    {
        return $this->response($this->exports->forUser($request->user(), $request->query(), $format));
    }

    public function publicDownload(ReportRequest $request, string $format): BinaryFileResponse
    {
        return $this->response($this->exports->forPublic($request->query(), $format));
    }

    public function tree(ReportRequest $request, string $tree): BinaryFileResponse
    {
        return $this->response($this->exports->treeForUser($request->user(), $tree, $request->query()));
    }

    public function publicTree(ReportRequest $request, string $organization, string $tree): BinaryFileResponse
    {
        return $this->response($this->exports->treeForPublic($organization, $tree, $request->query()));
    }

    private function response(ExportArtifact $artifact): BinaryFileResponse
    {
        return response()->download($artifact->path, $artifact->filename, [
            'Content-Type' => $artifact->mime, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
            'X-Export-Row-Count' => (string) $artifact->rows,
        ])->deleteFileAfterSend(true);
    }
}
