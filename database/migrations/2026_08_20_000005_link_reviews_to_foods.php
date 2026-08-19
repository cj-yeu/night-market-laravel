<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reviews', 'food_id')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->unsignedBigInteger('food_id')->nullable()->after('night_market_id');
            });
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreign('food_id')->references('id')->on('foods')->cascadeOnDelete();
            $table->unique(['user_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'food_id']);
            $table->dropConstrainedForeignId('food_id');
        });
    }
};
