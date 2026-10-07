<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StaffAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['staff_user_id', 'action', 'subject_type', 'subject_id', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
