<?php

namespace Database\Seeders;

use App\Models\StaffPermission;
use App\Models\StaffRole;
use Illuminate\Database\Seeder;

class StaffRolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = config('staff_permissions.roles');
        $permissions = collect($roles)->flatten()->unique()->values();

        foreach ($permissions as $permission) {
            StaffPermission::query()->firstOrCreate(['name' => $permission]);
        }

        foreach ($roles as $roleName => $rolePermissions) {
            $role = StaffRole::query()->firstOrCreate(['name' => $roleName]);

            $role->permissions()->sync(
                StaffPermission::query()->whereIn('name', $rolePermissions)->pluck('id'),
            );
        }
    }
}
