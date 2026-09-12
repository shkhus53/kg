<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Khidmatguzar Directory sorts by full_name by default (and several
 * other screens ORDER BY it) — at scale that was a filesort with no
 * supporting index. Note: this does NOT help the leading-wildcard
 * `LIKE '%term%'` search itself (no B-tree index can), only the plain
 * alphabetical ORDER BY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('khidmatguzars', function (Blueprint $table) {
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::table('khidmatguzars', function (Blueprint $table) {
            $table->dropIndex(['full_name']);
        });
    }
};
