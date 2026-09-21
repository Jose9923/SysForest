<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class StaleMaintenanceException extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'El mantenimiento fue modificado por otro usuario.');
    }
}
