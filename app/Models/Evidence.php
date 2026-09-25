<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evidence extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'evidences';
    protected $guarded = ['*'];
    protected $hidden = ['disk', 'path', 'metadata'];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean', 'publication_version' => 'integer',
            'size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer',
            'uploaded_by' => 'integer', 'updated_by' => 'integer', 'deleted_by' => 'integer',
            'lock_version' => 'integer', 'metadata' => 'array',
            'captured_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function evidenceType(): BelongsTo
    {
        return $this->belongsTo(EvidenceType::class);
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(Tree::class)->withTrashed();
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(TreeInspection::class, 'tree_inspection_id');
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class)->withTrashed();
    }

    /** El CHECK de PostgreSQL garantiza un único propietario explícito. */
    public function ownerRecord(): Tree|TreeInspection|Maintenance
    {
        return match (true) {
            $this->tree_id !== null => $this->tree,
            $this->tree_inspection_id !== null => $this->inspection,
            default => $this->maintenance,
        };
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
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
        return $this->hasMany(EvidenceRevision::class)->orderByDesc('version');
    }
}
