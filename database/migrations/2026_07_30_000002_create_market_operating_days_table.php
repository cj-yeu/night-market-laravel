<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_operating_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('night_market_id')->constrained()->cascadeOnDelete();
            $table->string('day_of_week');
            $table->time('opening_time');
            $table->time('closing_time');
            $table->timestamps();

            $table->unique(['night_market_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_operating_days');
    }
};
