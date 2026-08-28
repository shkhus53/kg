<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duty_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('khidmatguzar_id')->constrained();
            $table->foreignId('department_id')->constrained();
            $table->unsignedInteger('source_row_number');
            $table->string('assignment_fingerprint');

            // Assignment-defining source fields
            $table->string('block_name')->nullable();
            $table->string('day')->nullable();
            $table->string('day_alias')->nullable();
            $table->string('seat')->nullable();
            $table->string('category')->nullable();
            $table->string('venue_name_raw');

            // Current attendance state placeholder for the future locked attendance
            // architecture (Phase 4). No event table yet; this column exists so the
            // duty_assignments row is ready to receive it without a later migration
            // that touches every existing row.
            $table->enum('current_status', ['pending', 'present', 'absent'])->default('pending');

            // Person snapshot at import time (independent of khidmatguzars master)
            $table->string('full_name_snapshot');
            $table->string('gender_snapshot')->nullable();
            $table->string('age_snapshot')->nullable();
            $table->string('idara_snapshot')->nullable();
            $table->string('jamaat_snapshot')->nullable();
            $table->string('jamiaat_snapshot')->nullable();

            // Session-level source context carried per row
            $table->string('h_year')->nullable();
            $table->string('miqaat')->nullable();

            // Source-only fields, preserved verbatim, never interpreted as attendance
            $table->string('status_raw')->nullable();
            $table->string('allocated_user_name')->nullable();
            $table->string('allocated_date')->nullable();
            $table->string('deallocated_user_name')->nullable();
            $table->string('deallocated_date')->nullable();
            $table->string('scanned')->nullable();
            $table->string('acc_child_below_5yrs')->nullable();
            $table->string('multiple_acc_child_above_4yrs')->nullable();

            $table->timestamps();

            $table->index(['duty_session_id', 'assignment_fingerprint']);
            $table->index(['duty_session_id', 'khidmatguzar_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duty_assignments');
    }
};
