<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duty_sessions', function (Blueprint $table) {
            // Set true when an Admin reopens a Closed session for correction,
            // and cleared again on the next successful close. Session status
            // itself goes back to 'active' (reopening does NOT reset to
            // draft/pending) — this flag is only what lets the UI badge
            // distinguish "reopened for correction" from a normal Active
            // session.
            $table->boolean('is_reopened_for_correction')->default(false)->after('status');
            $table->timestamp('reopened_at')->nullable()->after('is_reopened_for_correction');
            $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('duty_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['is_reopened_for_correction', 'reopened_at']);
        });
    }
};
