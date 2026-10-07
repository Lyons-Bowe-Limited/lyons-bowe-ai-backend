<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class StaffUser extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'first_name', 'last_name', 'email', 'password', 'job_title',
        'primary_office_id', 'status', 'email_verified_at', 'activated_at',
        'last_login_at', 'password_changed_at', 'invited_by', 'deactivated_at',
        'deactivated_by', 'deactivation_reason',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        static::saving(function (StaffUser $user): void {
            $user->email = mb_strtolower(trim($user->email));
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(StaffRole::class, 'staff_role_user');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by');
    }

    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deactivated_by');
    }
}
