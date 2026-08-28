<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duty_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('date');
            $table->string('h_year')->nullable();
            $table->string('miqaat')->nullable();
            $table->text('remarks')->nullable();
            $table->enum('status', ['draft', 'active', 'closing', 'closed'])->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duty_sessions');
    }
};
