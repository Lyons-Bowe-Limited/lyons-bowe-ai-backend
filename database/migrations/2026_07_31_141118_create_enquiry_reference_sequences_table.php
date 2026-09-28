<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'enquiry_reference_sequences',
            function (Blueprint $table) {
                $table->id();

                $table->string('prefix', 20);
                $table->date('sequence_date');
                $table->unsignedBigInteger('current_number')
                    ->default(0);

                $table->timestampsTz();

                $table->unique(
                    ['prefix', 'sequence_date'],
                    'enquiry_reference_sequences_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_reference_sequences');
    }
};