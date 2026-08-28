<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->index(['department_id', 'current_status']);
            $table->index(['khidmatguzar_id', 'current_status']);
        });

        Schema::table('duty_sessions', function (Blueprint $table) {
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'current_status']);
            $table->dropIndex(['khidmatguzar_id', 'current_status']);
        });

        Schema::table('duty_sessions', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });
    }
};
