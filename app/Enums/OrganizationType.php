<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganizationType: string
{
    case School = 'school';
    case Municipality = 'municipality';
    case DepartmentEntity = 'department_entity';
    case NationalEntity = 'national_entity';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::School => 'Institución educativa',
            self::Municipality => 'Entidad municipal',
            self::DepartmentEntity => 'Entidad departamental',
            self::NationalEntity => 'Entidad nacional',
            self::Other => 'Otra organización',
        };
    }
}
