<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        if ($sqlite) {
            // Avoid rebuilding enquiries and cascading into historical audit rows.
            DB::statement('ALTER TABLE enquiries ADD COLUMN priority_set_by_staff_id INTEGER REFERENCES staff_users(id) ON DELETE SET NULL');
        }
        Schema::table('enquiries', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->foreignId('priority_set_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            }
            $table->index('priority_set_by_staff_id');
            $table->string('priority_source')->nullable();
            $table->string('priority_reason', 500)->nullable();
            $table->decimal('priority_confidence', 5, 4)->nullable();
            $table->timestampTz('priority_set_at')->nullable();
            $table->index(['priority', 'priority_set_at']);
        });
        Schema::table('ai_conversation_memories', fn (Blueprint $table) => $table->json('urgency')->nullable());
    }

    public function down(): void
    {
        Schema::table('ai_conversation_memories', fn (Blueprint $table) => $table->dropColumn('urgency'));
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropForeign(['priority_set_by_staff_id']);
            $table->dropIndex(['priority_set_by_staff_id']);
            $table->dropIndex(['priority', 'priority_set_at']);
            $table->dropColumn(['priority_source', 'priority_reason', 'priority_confidence', 'priority_set_at', 'priority_set_by_staff_id']);
        });
    }
};
