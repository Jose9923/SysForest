<?php

declare(strict_types=1);

namespace App\Exceptions\Inspections;

use Symfony\Component\HttpKernel\Exception\HttpException;

class StaleInspectionException extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'Esta inspección fue modificada por otro usuario. Actualiza la ficha antes de continuar.');
    }
}
