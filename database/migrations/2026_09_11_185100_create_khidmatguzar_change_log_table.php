<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('khidmatguzar_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('khidmatguzar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index('khidmatguzar_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('khidmatguzar_change_logs');
    }
};
