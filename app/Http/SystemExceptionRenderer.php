<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\StaleInspectionException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Respaldo HTTP seguro: APP_DEBUG conserva el diagnóstico interno, nunca lo convierte en contenido público. */
class SystemExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        // Laravel conserva los errores de campo, el input no sensible y los redirects de autenticación.
        if ($exception instanceof ValidationException || $exception instanceof AuthenticationException
            || $exception instanceof HttpResponseException) {
            return null;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $message = match ($status) {
            400 => 'La solicitud no tiene un formato válido.',
            401 => 'La sesión no es válida. Inicia sesión nuevamente.',
            403 => 'No tienes permiso para realizar esta operación.',
            404 => 'La información solicitada no está disponible.',
            405 => 'Esta dirección no admite la operación solicitada.',
            409 => $exception instanceof StaleInspectionException
                ? 'Esta inspección fue modificada por otro usuario. Actualiza la ficha antes de continuar.'
                : 'Este registro fue modificado por otro usuario. Actualiza la ficha antes de continuar.',
            413 => 'La solicitud supera el tamaño máximo permitido.',
            415 => $request->routeIs('mobile.trees.photos.store')
                ? 'Envía Content-Type: multipart/form-data con su boundary.'
                : 'Envía Content-Type: application/json.',
            419 => 'El formulario caducó. Actualiza la página antes de continuar.',
            422 => 'Revisa los datos indicados en el formulario.',
            429 => 'Has realizado demasiadas solicitudes. Espera antes de volver a intentarlo.',
            default => 'No fue posible completar la operación. Inténtalo nuevamente en unos momentos.',
        };
        if ($request->is('api/mobile/v1/*') && $status === 409) {
            $code = match (true) {
                $exception instanceof \App\Exceptions\MobileIdempotencyConflictException => 'idempotency_conflict',
                $exception instanceof \App\Exceptions\StaleRecordException,
                $exception instanceof StaleInspectionException => 'version_conflict',
                default => 'conflict',
            };
            if ($code === 'idempotency_conflict') {
                $message = 'El identificador de la operación ya fue confirmado con otro contenido, usuario o destino. Conserva la operación original y revisa el conflicto.';
            }

            return response()->json(['message' => $message, 'code' => $code], 409);
        }
        $backUrl = $exception instanceof StaleInspectionException ? route('inspections.index') : null;
        $response = $request->is('api/*') || $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : response()->view('errors.system', compact('message', 'status', 'backUrl'), $status);

        if ($exception instanceof HttpExceptionInterface) {
            $response->headers->add($exception->getHeaders());
        }

        return $response;
    }

    /** Se ejecuta también si un middleware aborta antes de devolver control a identity.headers. */
    public function secure(Response $response): Response
    {
        $response->headers->add([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ]);
        if (!$response->headers->has('Content-Security-Policy')) {
            // No bloquea scripts/estilos legítimos; conserva las políticas más estrictas de archivos privados.
            $response->headers->set('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}
