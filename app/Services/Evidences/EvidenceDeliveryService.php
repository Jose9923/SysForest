<?php

declare(strict_types=1);

namespace App\Services\Evidences;

use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Autoriza antes de abrir el original; no hay URLs públicas, ETag ni respuesta condicional sin sesión. */
class EvidenceDeliveryService
{
    public function __construct(private EvidenceReadService $reader, private EvidenceStorageService $storage)
    {
    }

    public function response(User $actor, string $id, bool $download = false): StreamedResponse
    {
        $evidence = $this->reader->authorizeExisting($actor, $id);
        try {
            $stream = $this->storage->read($evidence);
        } catch (Throwable) {
            throw new HttpException(404, 'El archivo de esta evidencia no está disponible.');
        }
        $headers = ['Content-Type' => $evidence->mime_type, 'Content-Length' => (string) $evidence->size_bytes,
            'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$evidence->id.'.'.$evidence->extension.'"'];

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }
}
