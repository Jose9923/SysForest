<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Una sesión vencida o un cambio de institución no debe reenviar el formulario a otra identidad. */
class SessionContinuityException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $state, string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public function render(Request $request): Response
    {
        if ($request->is('auth/session/*') || $request->expectsJson()) {
            return response()->json(['state' => $this->state, 'message' => $this->getMessage()], $this->status);
        }

        return response()->view('errors.system', [
            'message' => $this->getMessage(), 'status' => $this->status, 'backUrl' => null,
        ], $this->status);
    }
}
