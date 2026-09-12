<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('miqaat_id')->constrained('miqaats');
            $table->foreignId('event_id')->constrained('events');
            $table->foreignId('venue_id')->constrained('venues');
            $table->date('planned_date');
            $table->string('h_year')->nullable();
            $table->string('status')->default('draft'); // draft|finalized

            // Frozen at creation time — the Phase 3 forecast the operator
            // actually saw. Never recalculated even if historical data or
            // the forecasting algorithm changes later (historical integrity).
            $table->json('forecast_snapshot')->nullable();

            // Phase 4 working set: one row per department with recommended
            // (frozen from forecast_snapshot) + planned (mutable). See
            // EventPlanningService for shape.
            $table->json('departments')->nullable();

            $table->unsignedInteger('recommended_total')->default(0);
            $table->unsignedInteger('planned_total')->default(0);

            $table->foreignId('duty_session_id')->nullable()->constrained('duty_sessions')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->index(['miqaat_id', 'event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_plans');
    }
};
