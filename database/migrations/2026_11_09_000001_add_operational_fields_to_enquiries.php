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
            // SQLite supports an inline nullable FK on ADD COLUMN. Avoid a
            // table rebuild, which can cascade-delete existing audit rows.
            DB::statement('ALTER TABLE enquiries ADD COLUMN assigned_staff_id INTEGER REFERENCES staff_users(id) ON DELETE SET NULL');
        }

        Schema::table('enquiries', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->foreignId('assigned_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            }
            $table->index('assigned_staff_id');
            $table->string('matter_type')->nullable();
            $table->timestampTz('qualified_at')->nullable()->index();
            // submitted_at already exists and is indexed.
            $table->decimal('classification_confidence', 5, 4)->nullable();
            $table->string('classification_source')->nullable();
            $table->boolean('requires_review')->default(true)->index();
            $table->string('handoff_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropForeign(['assigned_staff_id']);
            $table->dropIndex(['assigned_staff_id']);
            $table->dropIndex(['qualified_at']);
            $table->dropIndex(['requires_review']);
            $table->dropColumn(['assigned_staff_id', 'matter_type', 'qualified_at', 'classification_confidence', 'classification_source', 'requires_review', 'handoff_reason']);
        });
    }
};
