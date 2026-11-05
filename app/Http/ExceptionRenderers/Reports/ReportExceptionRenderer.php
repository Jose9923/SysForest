<?php

declare(strict_types=1);

namespace App\Http\ExceptionRenderers\Reports;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Los fallos de descargas no revelan rutas, consultas ni trazas aunque APP_DEBUG esté activo. */
class ReportExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        if (!$request->is('reports', 'reports/*', 'analytics', 'api/v1/analytics') || $exception instanceof AuthenticationException) {
            return null;
        }
        $status = $exception instanceof ValidationException ? 422
            : ($exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503);
        $message = match ($status) {
            403 => 'No tienes permiso para consultar o exportar este reporte en el alcance solicitado.',
            404 => 'El reporte o individuo solicitado no está disponible.',
            405 => 'Los reportes solo admiten consultas de lectura.',
            422 => 'Revisa los filtros y los límites de la descarga.',
            429 => 'Has realizado demasiadas consultas o descargas. Espera un minuto e inténtalo de nuevo.',
            default => 'No fue posible preparar el reporte. Inténtalo nuevamente.',
        };
        $data = ['message' => $message];
        if ($exception instanceof ValidationException) {
            $data['errors'] = $exception->errors();
        }
        $response = ($request->is('api/*') || $request->expectsJson()) ? response()->json($data, $status)
            : response()->view('reports.error', ['message' => $message, 'validationErrors' => $data['errors'] ?? []], $status);
        if ($exception instanceof HttpExceptionInterface) {
            $response->headers->add($exception->getHeaders());
        }
        $response->headers->add(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY']);

        return $response;
    }
}
