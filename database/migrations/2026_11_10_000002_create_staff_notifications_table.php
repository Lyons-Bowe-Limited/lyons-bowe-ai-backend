<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('staff_user_id')->constrained('staff_users')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->foreignUuid('enquiry_id')->nullable()->constrained('enquiries')->nullOnDelete();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->index(['staff_user_id', 'created_at', 'id']);
            $table->index(['staff_user_id', 'read_at']);
            $table->index('enquiry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
    }
};
