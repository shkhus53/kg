<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departments start as an import-side-effect (firstOrCreate on the "Venue
 * Name" column) with only name/normalized_key. This adds the fields needed
 * to curate them as a real admin-managed master, all nullable or defaulted
 * so the existing auto-create-on-import path needs no change and every
 * existing row is valid the moment this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name');
            $table->text('description')->nullable()->after('code');
            $table->boolean('active')->default(true)->after('description');
            $table->unsignedInteger('sort_order')->default(0)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['code', 'description', 'active', 'sort_order']);
        });
    }
};
