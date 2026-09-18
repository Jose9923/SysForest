<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class StaleEvidenceException extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'La evidencia fue modificada por otro usuario.');
    }
}
