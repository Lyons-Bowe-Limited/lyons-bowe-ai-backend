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
        // Role data is retained to avoid removing permissions from live staff accounts.
    }
};
