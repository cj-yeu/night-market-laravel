<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // The existing food-review unique index is also the only index whose
            // leftmost column is user_id, so MySQL uses it for the user foreign key.
            // Keep that foreign key independently indexed before removing the
            // permanent per-target uniqueness constraints.
            $table->index('user_id', 'reviews_user_id_index');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_user_market_unique');
            $table->dropUnique('reviews_user_id_food_id_unique');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->date('review_date')->nullable()->after('status');
        });

        DB::table('reviews')->orderBy('id')->eachById(function ($review): void {
            DB::table('reviews')->where('id', $review->id)->update(['review_date' => Carbon::parse($review->created_at)->toDateString()]);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->date('review_date')->nullable(false)->change();
            $table->unique(['user_id', 'night_market_id', 'review_date'], 'reviews_user_market_date_unique');
            $table->unique(['user_id', 'food_id', 'review_date'], 'reviews_user_food_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_user_market_date_unique');
            $table->dropUnique('reviews_user_food_date_unique');
            $table->unique(['user_id', 'night_market_id'], 'reviews_user_market_unique');
            $table->unique(['user_id', 'food_id']);
            $table->dropIndex('reviews_user_id_index');
            $table->dropColumn('review_date');
        });
    }
};
