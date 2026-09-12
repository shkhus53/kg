<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_reopen_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reopened_by')->constrained('users');
            $table->enum('reason', [
                'attendance_correction_required',
                'incorrect_attendance_marked',
                'assignment_correction_required',
                'duty_list_import_correction',
                'session_closed_accidentally',
                'other',
            ]);
            $table->text('detail')->nullable();
            $table->timestamp('reopened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['duty_session_id', 'reopened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_reopen_events');
    }
};
