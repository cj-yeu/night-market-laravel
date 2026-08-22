<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Food-linked legacy rows have an explicit target, so clearing their redundant market
        // reference preserves the target without inferring or assigning any relationship.
        DB::table('reviews')->whereNotNull('food_id')->update(['night_market_id' => null]);

        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('night_market_id')->nullable()->change();
            $table->unique(['user_id', 'night_market_id'], 'reviews_user_market_unique');
        });

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_exactly_one_target_check CHECK ((night_market_id IS NOT NULL AND food_id IS NULL) OR (night_market_id IS NULL AND food_id IS NOT NULL))');
    }

    public function down(): void
    {
        // Restore the historical redundant market reference only from each Food's known Stall.
        DB::statement('UPDATE reviews INNER JOIN foods ON foods.id = reviews.food_id INNER JOIN stalls ON stalls.id = foods.stall_id SET reviews.night_market_id = stalls.night_market_id WHERE reviews.food_id IS NOT NULL');

        DB::statement('ALTER TABLE reviews DROP CONSTRAINT reviews_exactly_one_target_check');

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_user_market_unique');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('night_market_id')->nullable(false)->change();
        });
    }
};
