<?php

declare(strict_types=1);

namespace App\Exceptions\Mobile;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Distingue un identificador reutilizado de una edición basada en una versión antigua. */
class MobileIdempotencyConflictException extends ConflictHttpException
{
}
