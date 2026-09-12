<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Repositories\OrganizationSequenceRepository;

class TreeCodeService
{
    public function __construct(private OrganizationSequenceRepository $sequences)
    {
    }

    /** Seis dígitos es un ancho mínimo; nunca se recicla un código de un registro confirmado o anulado. */
    public function next(Organization $organization): string
    {
        return $organization->tree_code_prefix.'-'.str_pad((string) $this->sequences->nextTreeValue($organization->id), 6, '0', STR_PAD_LEFT);
    }
}
