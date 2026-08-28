<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->timestamp('attendance_marked_at')->nullable()->after('current_status');
            $table->foreignId('attendance_marked_by')->nullable()->after('attendance_marked_at')->constrained('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_marked_by');
            $table->dropColumn('attendance_marked_at');
        });
    }
};
