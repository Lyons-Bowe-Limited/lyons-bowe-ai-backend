<?php

namespace App\Services;

use App\Models\StaffUser;
use Illuminate\Support\Collection;

class StaffAuthorisationService
{
    public function role(StaffUser $staffUser): string
    {
        $roleNames = $staffUser->roles()->pluck('name');

        foreach (config('staff_permissions.role_priority', []) as $role) {
            if ($roleNames->contains($role)) {
                return $role;
            }
        }

        return (string) ($roleNames->sort()->first() ?? '');
    }

    /** @return Collection<int, string> */
    public function permissions(StaffUser $staffUser): Collection
    {
        return $staffUser->roles()
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->sort()
            ->values();
    }

    public function allows(StaffUser $staffUser, string $permission): bool
    {
        return $this->permissions($staffUser)->contains($permission);
    }
}
