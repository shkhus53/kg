<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (Create Event Session): links a DutySession to the Phase 1
 * master-data hierarchy. All three are nullable — every session created
 * before this migration has none of them, and that is a permanent, valid
 * state (a "legacy session"), not a gap to backfill or fabricate data for.
 *
 * nullOnDelete (not cascadeOnDelete): master-data rows are deactivated,
 * never hard-deleted in this app's UI — but if a master row were ever
 * removed by some other means, a DutySession and its entire attendance
 * history must never disappear with it. Losing the reference is
 * acceptable; losing the session is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duty_sessions', function (Blueprint $table) {
            $table->foreignId('miqaat_id')->nullable()->after('miqaat')->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->after('miqaat_id')->constrained()->nullOnDelete();
            $table->foreignId('venue_id')->nullable()->after('event_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('duty_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('miqaat_id');
            $table->dropConstrainedForeignId('event_id');
            $table->dropConstrainedForeignId('venue_id');
        });
    }
};
