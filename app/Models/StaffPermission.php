<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StaffPermission extends Model
{
    protected $fillable = ['name'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(StaffRole::class, 'staff_permission_role');
    }
}
