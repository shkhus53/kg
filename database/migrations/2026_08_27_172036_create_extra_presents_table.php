<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_presents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('khidmatguzar_id')->constrained();
            $table->string('its_id_snapshot');
            $table->string('full_name_snapshot');
            $table->foreignId('department_id')->constrained();
            $table->string('department_name_snapshot');
            $table->foreignId('marked_by')->constrained('users');
            $table->timestamp('marked_at');
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->unique(['duty_session_id', 'khidmatguzar_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_presents');
    }
};
