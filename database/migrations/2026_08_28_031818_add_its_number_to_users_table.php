<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * its_number is the login identifier (ITS-based auth). Stored as a fixed-width
     * string, never an integer, so leading zeroes survive. Kept nullable at the DB
     * level — this app has no self-registration, so "required" is enforced by the
     * admin-bootstrap command and any future user-creation path, not by a NOT NULL
     * constraint that would need a backfill migration to satisfy on existing rows.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('its_number', 8)->nullable()->unique()->after('name');
        });

        // Backfill any pre-existing rows (dev seeder accounts) with a placeholder
        // ITS so the unique index has no null-vs-null collisions to worry about
        // and every row is queryable by its_number going forward.
        foreach (DB::table('users')->whereNull('its_number')->orderBy('id')->get(['id']) as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'its_number' => str_pad((string) (90000000 + $user->id), 8, '0', STR_PAD_LEFT),
            ]);
        }

        // Login is now ITS-based, not email-based, but `email` stays NOT NULL —
        // changing that needs doctrine/dbal (not installed) and would behave
        // differently on the sqlite test connection than on MySQL. Instead,
        // ITS-only accounts (see CreateAdminCommand) get a synthetic placeholder
        // email so the column keeps its existing constraint unchanged.
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['its_number']);
            $table->dropColumn('its_number');
        });
    }
};
