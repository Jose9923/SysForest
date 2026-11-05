<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mobile;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileApiHeaders
{
    private const int MAX_BODY_BYTES = 1048576;

    public function handle(Request $request, Closure $next): Response
    {
        $photoUpload = $request->routeIs('mobile.trees.photos.store');
        $maximum = $photoUpload
            ? max(1, (int) config('evidence.max_size_mb', 10)) * 1048576 + 65536
            : self::MAX_BODY_BYTES;
        if ((int) $request->header('Content-Length', '0') > $maximum
            || (!$photoUpload && strlen($request->getContent()) > $maximum)) {
            abort(413, 'La solicitud supera el tamaño máximo permitido.');
        }

        if ($photoUpload) {
            $contentType = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
            if ($contentType !== 'multipart/form-data') {
                abort(415, 'Envía Content-Type: multipart/form-data con su boundary.');
            }
            // PHP ya separó el multipart: limita campos y archivos incluso si faltaba Content-Length.
            $size = strlen(json_encode($request->request->all(), JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            foreach (\Illuminate\Support\Arr::flatten($request->allFiles()) as $file) {
                if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->isValid()) {
                    $size += (int) $file->getSize();
                }
            }
            if ($size > $maximum) {
                abort(413, 'La solicitud supera el tamaño máximo permitido.');
            }
        } elseif (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            if (! $request->isJson()) {
                abort(415, 'Envía Content-Type: application/json.');
            }
            try {
                $input = json_decode($request->getContent(), false, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                abort(400, 'El cuerpo JSON no es válido.');
            }
            if (! is_object($input)) {
                abort(400, 'El cuerpo JSON debe ser un objeto.');
            }
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->setVary('Authorization', false);

        return $response;
    }
}
