<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('khidmatguzars', function (Blueprint $table) {
            $table->id();
            $table->string('its_id')->unique();
            $table->string('full_name');
            $table->string('gender')->nullable();
            $table->string('idara')->nullable();
            $table->string('jamaat')->nullable();
            $table->string('jamiaat')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('khidmatguzars');
    }
};
