<?php

use Database\Seeders\StaffRolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // A stale deployment config cache must not retain revoked global grants.
        config(['staff_permissions' => require config_path('staff_permissions.php')]);
        (new StaffRolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // Do not re-grant global access to solicitors when rolling back.
    }
};
