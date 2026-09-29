<?php

declare(strict_types=1);

namespace App\Services;

use App\Auditing\AuditSnapshot;

/** Contrato de captura de los servicios de negocio; la proyección pura no consulta la base. */
class AuditSnapshotService extends AuditSnapshot
{
}
