<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiry_workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('enquiry_id')
                ->constrained('enquiries')
                ->cascadeOnDelete();

            $table->string('workflow_key');
            $table->string('workflow_version')->default('1.0');

            $table->string('status')->default('in_progress');

            $table->string('current_step_key')->nullable();
            $table->string('previous_step_key')->nullable();

            $table->unsignedInteger('answered_steps')->default(0);
            $table->unsignedInteger('total_applicable_steps')->default(0);

            $table->jsonb('state')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->unique('enquiry_id');
            $table->index(['workflow_key', 'status']);
            $table->index('current_step_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_workflows');
    }
};