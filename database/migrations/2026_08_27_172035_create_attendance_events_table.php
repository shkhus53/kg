<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('duty_session_id')->constrained();
            $table->foreignId('khidmatguzar_id')->constrained();
            $table->enum('action', ['present', 'absent']);
            $table->foreignId('performed_by')->constrained('users');
            $table->timestamp('performed_at');
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['duty_assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
    }
};
