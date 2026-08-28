<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('original_filename');
            $table->string('file_type', 10);
            $table->enum('status', ['completed', 'failed'])->default('completed');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('exact_duplicate_rows')->default(0);
            $table->unsignedInteger('new_khidmatguzars')->default(0);
            $table->unsignedInteger('existing_khidmatguzars')->default(0);
            $table->unsignedInteger('new_departments')->default(0);
            $table->unsignedInteger('existing_departments')->default(0);
            $table->json('error_summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
