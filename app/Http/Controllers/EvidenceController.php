<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RestoreEvidenceRequest;
use App\Http\Requests\StoreEvidenceRequest;
use App\Http\Requests\UpdateEvidenceRequest;
use App\Http\Requests\VoidEvidenceRequest;
use App\Services\EvidenceDeliveryService;
use App\Services\EvidenceReadService;
use App\Services\EvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceController extends Controller
{
    public function __construct(private EvidenceService $evidences, private EvidenceReadService $reader, private EvidenceDeliveryService $delivery)
    {
    }

    public function index(Request $request): View
    {
        return view('evidences.index', $this->reader->index($request->user(), $request->route('tree'), $request->route('inspection'), $request->query()));
    }

    public function create(Request $request): View
    {
        return view('evidences.upload', $this->reader->uploadForm($request->user(), $request->route('tree'), $request->route('inspection')));
    }

    public function store(StoreEvidenceRequest $request): JsonResponse|RedirectResponse
    {
        $records = $this->evidences->upload($request->user(), $request->route('tree'), $request->route('inspection'), $request->validated());
        $url = $this->reader->ownerUrl($records->first());
        $message = 'Fotografías adjuntadas correctamente.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'redirect' => $url], 201)
            : redirect()->to($url)->with('status', $message);
    }

    public function show(Request $request, string $evidence): View
    {
        return view('evidences.show', $this->reader->show($request->user(), $evidence));
    }

    public function update(UpdateEvidenceRequest $request, string $evidence): RedirectResponse
    {
        $this->evidences->update($request->user(), $evidence, $request->validated());

        return redirect()->route('evidences.show', $evidence)->with('status', 'Evidencia actualizada con auditoría.');
    }

    public function destroy(VoidEvidenceRequest $request, string $evidence): RedirectResponse
    {
        $record = $this->evidences->void($request->user(), $evidence, $request->validated());

        return redirect()->to($this->reader->ownerUrl($record))->with('status', 'Evidencia anulada. El archivo se conserva.');
    }

    public function restore(RestoreEvidenceRequest $request, string $evidence): RedirectResponse
    {
        $this->evidences->restore($request->user(), $evidence, $request->validated());

        return redirect()->route('evidences.show', $evidence)->with('status', 'Evidencia restaurada.');
    }

    public function view(Request $request, string $evidence): StreamedResponse
    {
        return $this->delivery->response($request->user(), $evidence);
    }

    public function download(Request $request, string $evidence): StreamedResponse
    {
        return $this->delivery->response($request->user(), $evidence, true);
    }
}
