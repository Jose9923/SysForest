<?php

declare(strict_types=1);

namespace App\Http\ExceptionRenderers\Imports;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Los fallos no muestran XML, SQL, rutas temporales ni trazas, tampoco con APP_DEBUG activo. */
class ImportExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        if (!$request->is('imports', 'imports/*') || $exception instanceof AuthenticationException) {
            return null;
        }
        $status = $exception instanceof ValidationException ? 422
            : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
        $message = match ($status) {
            403 => 'No tienes permiso para trabajar con importaciones en esta organización.',
            404 => 'El lote o la campaña no están disponibles en la organización seleccionada.',
            409 => 'El lote cambió o ya no admite esta operación. Abre su resultado antes de continuar.',
            413 => 'El archivo supera el tamaño permitido.',
            419 => 'El formulario caducó. Actualiza la página antes de continuar.',
            422 => 'Revisa el archivo y los datos indicados.',
            429 => 'Has realizado demasiadas solicitudes. Espera un minuto antes de volver a intentarlo.',
            default => 'No fue posible completar la importación. Consulta el lote antes de volver a confirmar.',
        };
        $errors = $exception instanceof ValidationException ? $exception->errors() : [];
        $response = $request->expectsJson() ? response()->json(['message' => $message, 'errors' => $errors], $status)
            : response()->view('imports.error', compact('message', 'errors'), $status);
        if ($exception instanceof HttpExceptionInterface) {
            $response->headers->add($exception->getHeaders());
        }
        $response->headers->add(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY']);

        return $response;
    }
}
