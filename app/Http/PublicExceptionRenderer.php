<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Impide que APP_DEBUG o un error de infraestructura revelen SQL, rutas, credenciales o trazas. */
class PublicExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        if (! $request->is('portal', 'portal/*', 'api/public/v1', 'api/public/v1/*')) {
            return null;
        }
        $status = $exception instanceof ValidationException ? 422
            : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
        $message = match ($status) {
            404 => 'La información solicitada no está disponible para consulta pública.',
            405 => 'Esta consulta solo admite lectura.',
            422 => 'Revisa los filtros y los valores de la consulta.',
            429 => 'Has realizado demasiadas consultas. Espera unos segundos e inténtalo de nuevo.',
            default => 'No fue posible consultar la información pública. Inténtalo de nuevo en unos momentos.',
        };
        $data = ['message' => $message];
        if ($exception instanceof ValidationException) {
            $data['errors'] = $exception->errors();
        }
        $response = $request->is('api/*') || $request->expectsJson()
            ? response()->json($data, $status)
            : response()->view('public.error', $data + ['status' => $status, 'validationErrors' => $data['errors'] ?? [], 'seo' => [
                'title' => 'Consulta no disponible · SYSFOREST', 'description' => $message,
                'canonical' => rtrim((string) config('app.url'), '/').'/portal',
            ]], $status);
        if ($exception instanceof HttpExceptionInterface) {
            $response->headers->add($exception->getHeaders());
        }
        $response->headers->add(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY']);

        return $response;
    }
}
