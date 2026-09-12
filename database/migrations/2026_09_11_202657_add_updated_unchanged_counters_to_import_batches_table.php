<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (Intelligent Import Center): existing_khidmatguzars previously
 * conflated "already in master, no field changed" with "already in
 * master, some field changed by this import" — the diff-aware preview
 * needs to distinguish them explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('updated_khidmatguzars')->default(0)->after('existing_khidmatguzars');
            $table->unsignedInteger('unchanged_khidmatguzars')->default(0)->after('updated_khidmatguzars');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['updated_khidmatguzars', 'unchanged_khidmatguzars']);
        });
    }
};
