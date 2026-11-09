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
            DB::statement('ALTER TABLE enquiry_events ADD COLUMN performed_by_staff_id INTEGER REFERENCES staff_users(id) ON DELETE SET NULL');
        }
        Schema::table('enquiry_events', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->foreignId('performed_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            }
            $table->index('performed_by_staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('enquiry_events', function (Blueprint $table) {
            $table->dropForeign(['performed_by_staff_id']);
            $table->dropIndex(['performed_by_staff_id']);
            $table->dropColumn('performed_by_staff_id');
        });
    }
};
