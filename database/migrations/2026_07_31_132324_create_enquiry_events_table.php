<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiry_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('enquiry_id')
                ->constrained('enquiries')
                ->cascadeOnDelete();

            $table->foreignUuid('workflow_id')
                ->nullable()
                ->constrained('enquiry_workflows')
                ->cascadeOnDelete();

            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('step_key')->nullable();

            $table->jsonb('metadata')->nullable();

            $table->timestampTz('created_at');

            $table->index(['enquiry_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_events');
    }
};