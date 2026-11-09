<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('enquiry_workflows', 'last_activity_at')) {
            Schema::table('enquiry_workflows', fn (Blueprint $table) => $table->timestampTz('last_activity_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('enquiry_workflows', fn (Blueprint $table) => $table->dropColumn('last_activity_at'));
    }
};
