<?php

declare(strict_types=1);

namespace App\Http\Middleware\Infrastructure;

use App\Http\ExceptionRenderers\Infrastructure\SystemExceptionRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cubre respuestas de error explícitas que Routing Pipeline entrega sin pasar por el handler de excepciones. */
class SecureErrorResponses
{
    public function __construct(private readonly SystemExceptionRenderer $renderer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $response->getStatusCode() >= 400 ? $this->renderer->secure($response) : $response;
    }
}
