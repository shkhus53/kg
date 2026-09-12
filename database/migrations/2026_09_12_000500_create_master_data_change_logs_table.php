<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One shared, append-only audit trail for every master-data entity
 * (department/miqaat/event/venue), mirroring the existing
 * khidmatguzar_change_logs pattern rather than inventing a new mechanism
 * or a table per entity type. entity_id is a plain column, not a
 * polymorphic FK, since the four entity tables have no shared parent —
 * this table only ever records what changed, never enforces referential
 * integrity back to a live row (a deleted/deactivated entity's history
 * must remain readable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_change_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_change_logs');
    }
};
