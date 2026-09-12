<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event identity is scoped per Miqaat, not global — "Qadambosi Bethak"
 * under one Miqaat and "Qadambosi Bethak" under another are two distinct,
 * legitimate rows (approved decision). Uniqueness is therefore on
 * (miqaat_id, normalized_key), not on normalized_key alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('miqaat_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_key');
            $table->string('code')->nullable();
            $table->string('family')->nullable();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['miqaat_id', 'normalized_key']);
            $table->index('family');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
