<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffInvitation extends Model
{
    protected $fillable = [
        'staff_user_id', 'email', 'token_hash', 'expires_at', 'accepted_at',
        'cancelled_at', 'invited_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'invited_by');
    }
}
