<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('staff_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('staff_role_user', function (Blueprint $table) {
            $table->foreignId('staff_user_id')->constrained('staff_users')->cascadeOnDelete();
            $table->foreignId('staff_role_id')->constrained('staff_roles')->cascadeOnDelete();
            $table->primary(['staff_user_id', 'staff_role_id']);
        });

        Schema::create('staff_permission_role', function (Blueprint $table) {
            $table->foreignId('staff_role_id')->constrained('staff_roles')->cascadeOnDelete();
            $table->foreignId('staff_permission_id')->constrained('staff_permissions')->cascadeOnDelete();
            $table->primary(['staff_role_id', 'staff_permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_permission_role');
        Schema::dropIfExists('staff_role_user');
        Schema::dropIfExists('staff_permissions');
        Schema::dropIfExists('staff_roles');
    }
};
