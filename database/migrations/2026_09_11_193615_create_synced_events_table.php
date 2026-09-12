<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger + observability log for offline-queued attendance
 * events (Phase 2). event_id (client-generated UUID) is the idempotency
 * key: a retried sync of the same event finds this row and is never
 * re-applied to AttendanceService. session/assignment/khidmatguzar ids are
 * plain columns, not FKs — this table records what a client attempted,
 * which must be logged even if the reference turns out to be invalid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synced_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();

            $table->unsignedBigInteger('duty_session_id')->nullable();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->unsignedBigInteger('khidmatguzar_id')->nullable();

            $table->string('action');
            $table->string('context')->nullable();
            $table->json('payload')->nullable();

            // Historical metadata: who the client claims created this event,
            // vs who is actually authenticated on this sync request. These
            // differ only on an operator_mismatch — the server never trusts
            // the former for authorization.
            $table->unsignedBigInteger('operator_user_id')->nullable();
            $table->foreignId('synced_by_user_id')->nullable()->constrained('users');

            $table->string('device_id')->nullable();
            $table->unsignedInteger('local_sequence')->nullable();
            $table->timestamp('local_timestamp')->nullable();
            $table->string('package_version')->nullable();

            $table->unsignedInteger('attempt_count')->default(1);
            $table->timestamp('first_received_at');
            $table->timestamp('last_attempted_at');
            $table->string('last_result'); // accepted|duplicate|conflict|rejected|retryable_failure|operator_mismatch
            $table->text('detail')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->foreignId('attendance_event_id')->nullable()->constrained('attendance_events')->nullOnDelete();
            $table->foreignId('extra_present_id')->nullable()->constrained('extra_presents')->nullOnDelete();

            $table->timestamps();

            $table->index('duty_session_id');
            $table->index('device_id');
            $table->index('operator_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synced_events');
    }
};
