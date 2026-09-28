<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversation_memories', function (Blueprint $table) {

            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('ai_conversation_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             |--------------------------------------------------------------------------
             | Current Matter
             |--------------------------------------------------------------------------
             */

            $table->string('practice_area')->nullable();

            $table->string('matter_type')->nullable();

            $table->string('conversation_stage')
                ->default('information_gathering');

            /*
             |--------------------------------------------------------------------------
             | User Intent
             |--------------------------------------------------------------------------
             */

            $table->string('intent')->nullable();

            /*
             |--------------------------------------------------------------------------
             | AI Summary
             |--------------------------------------------------------------------------
             */

            $table->longText('summary')->nullable();

            /*
             |--------------------------------------------------------------------------
             | Extracted Entities
             |--------------------------------------------------------------------------
             */

            $table->json('entities')->nullable();

            /*
             |--------------------------------------------------------------------------
             | Confidence Scores
             |--------------------------------------------------------------------------
             */

            $table->decimal('practice_area_confidence',5,2)
                ->default(0);

            $table->decimal('intent_confidence',5,2)
                ->default(0);

            /*
             |--------------------------------------------------------------------------
             | Consultation
             |--------------------------------------------------------------------------
             */

            $table->boolean('consultation_recommended')
                ->default(false);

            $table->boolean('booking_presented')
                ->default(false);

            /*
             |--------------------------------------------------------------------------
             | Misc
             |--------------------------------------------------------------------------
             */

            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_memories');
    }
};