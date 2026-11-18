<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Auditing\AuditSnapshot;
use App\Repositories\Audit\AuditReferenceRepository;

/** Captura nueva con referencias externas; la proyección histórica pura permanece sin consultas. */
class AuditSnapshotService extends AuditSnapshot
{
    public function __construct(private AuditReferenceRepository $references)
    {
    }

    public function sanitize(string $type, array $values): array
    {
        return parent::sanitize($type, $this->references->snapshot($type, $values));
    }
}
