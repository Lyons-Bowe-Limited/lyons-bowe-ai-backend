<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiry_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('enquiry_id')
                ->constrained('enquiries')
                ->cascadeOnDelete();

            $table->foreignUuid('workflow_id')
                ->constrained('enquiry_workflows')
                ->cascadeOnDelete();

            $table->string('step_key');
            $table->string('question_key');

            $table->text('question_text');
            $table->string('answer_type');

            /*
             * Always store the answer as JSON:
             *
             * {"value": true}
             * {"value": "married"}
             * {"value": ["child_1", "child_2"]}
             */
            $table->jsonb('answer');

            $table->jsonb('normalised_answer')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->unsignedInteger('revision')->default(1);

            $table->timestampTz('answered_at');

            $table->timestampsTz();

            $table->unique(
                ['workflow_id', 'question_key'],
                'enquiry_answer_workflow_question_unique'
            );

            $table->index(['enquiry_id', 'step_key']);
            $table->index('question_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_answers');
    }
};