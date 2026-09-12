<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * assignment_fingerprint was only a plain (query-performance) index, so a
 * race between two overlapping imports could both pass preview and both
 * commit, producing true duplicate duty_assignments rows. This is the DB-
 * level backstop the other two dedup keys (khidmatguzars.its_id,
 * extra_presents' composite unique) already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->dropIndex(['duty_session_id', 'assignment_fingerprint']);
            $table->unique(['duty_session_id', 'assignment_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->dropUnique(['duty_session_id', 'assignment_fingerprint']);
            $table->index(['duty_session_id', 'assignment_fingerprint']);
        });
    }
};
