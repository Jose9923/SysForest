<?php

declare(strict_types=1);

namespace App\Exceptions\Shared;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StaleRecordException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Este registro fue modificado por otro usuario. Actualiza la ficha antes de continuar.');
    }
}
