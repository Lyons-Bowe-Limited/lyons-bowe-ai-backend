<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('enquiries')->whereNotNull('conversation_id')
            ->select('conversation_id')->groupBy('conversation_id')->havingRaw('COUNT(*) > 1')
            ->pluck('conversation_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot enforce one enquiry per conversation. Resolve duplicate conversation_id values deliberately, preserving records and audit history: '.$duplicates->implode(', '));
        }

        // PostgreSQL and SQLite permit multiple NULL values in a unique index.
        Schema::table('enquiries', fn (Blueprint $table) => $table->unique('conversation_id', 'enquiries_conversation_id_unique'));
    }

    public function down(): void
    {
        Schema::table('enquiries', fn (Blueprint $table) => $table->dropUnique('enquiries_conversation_id_unique'));
    }
};
