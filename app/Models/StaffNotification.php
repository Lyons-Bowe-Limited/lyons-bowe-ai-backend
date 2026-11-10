<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffNotification extends Model
{
    use HasUuids;

    public const ENQUIRY_ASSIGNED = 'enquiry_assigned';

    protected $fillable = ['staff_user_id', 'type', 'title', 'message', 'enquiry_id', 'read_at'];

    protected function casts(): array
    {
        return ['staff_user_id' => 'integer', 'read_at' => 'datetime'];
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }
}
