<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_links', function (Blueprint $table) {
            $table->enum('trigger_type', [
                'manual',
                'ai_recommended',
                'mandatory',
            ])->default('ai_recommended')->after('service');
        });
    }

    public function down(): void
    {
        Schema::table('booking_links', function (Blueprint $table) {
            $table->dropColumn('trigger_type');
        });
    }
};