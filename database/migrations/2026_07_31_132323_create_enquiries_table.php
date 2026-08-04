<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained('ai_conversations')
                ->nullOnDelete();

            $table->string('reference')->unique();
            $table->string('practice_area');
            $table->string('workflow_key');

            $table->string('status')->default('draft');
            $table->string('priority')->default('normal');

            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_phone')->nullable();

            $table->string('office')->nullable();
            $table->string('recommended_service')->nullable();

            $table->unsignedTinyInteger('completion_percentage')
                ->default(0);

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->jsonb('summary')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('last_activity_at')->nullable();

            $table->timestampsTz();

            $table->index(['practice_area', 'status']);
            $table->index(['workflow_key', 'status']);
            $table->index('conversation_id');
            $table->index('assigned_to');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};