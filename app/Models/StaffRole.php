<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StaffRole extends Model
{
    protected $fillable = ['name'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(StaffUser::class, 'staff_role_user');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(StaffPermission::class, 'staff_permission_role');
    }
}
