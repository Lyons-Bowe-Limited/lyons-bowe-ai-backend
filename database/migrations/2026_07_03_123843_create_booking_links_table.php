<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_links', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('practice_area');
            $table->string('office')->nullable();

            $table->string('service')->nullable();

            $table->string('booking_business_id')->nullable();

            $table->text('booking_url');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_links');
    }
};