<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('job_title')->nullable();
            // No offices table exists yet; this remains an indexed future reference.
            $table->unsignedBigInteger('primary_office_id')->nullable()->index();
            $table->enum('status', ['pending', 'active', 'deactivated'])->default('pending')->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->text('deactivation_reason')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_users');
    }
};
