<?php

declare(strict_types=1);

namespace App\Models\Maintenances;

use App\Enums\Maintenances\MaintenancePriority;
use App\Enums\Maintenances\MaintenanceStatus;
use App\Models\Concerns\HasNumericIdentifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Evidences\Evidence;
use App\Models\Catalogs\MaintenanceType;
use App\Models\Organizations\Organization;
use App\Models\Trees\Tree;
use App\Models\User;

class Maintenance extends Model
{
    use HasNumericIdentifiers;
    use SoftDeletes;

    protected $table = 'maintenances';
    protected $guarded = ['*'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'status' => MaintenanceStatus::class, 'priority' => MaintenancePriority::class,
            'scheduled_date' => 'immutable_date', 'performed_date' => 'immutable_date',
            'assigned_to' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer',
            'deleted_by' => 'integer', 'lock_version' => 'integer',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(Tree::class)->withTrashed();
    }

    public function maintenanceType(): BelongsTo
    {
        return $this->belongsTo(MaintenanceType::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MaintenanceRevision::class)->orderByDesc('version');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(MaintenanceStatusHistory::class)->orderByDesc('version');
    }

    public function statusHistories(): HasMany
    {
        return $this->statusHistory();
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class)->orderByDesc('created_at')->orderByDesc('id');
    }
}
