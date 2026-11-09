<?php

use Database\Seeders\StaffRolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new StaffRolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // Retain permission data on rollback, following existing staff migrations.
    }
};
