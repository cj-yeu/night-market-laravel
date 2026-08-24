<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reviews', 'review_date')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->date('review_date')->nullable()->after('food_id');
            });
        }

        // Preserve each existing review on the calendar date on which it was created.
        DB::table('reviews')->whereNull('review_date')->update([
            'review_date' => DB::raw('DATE(created_at)'),
        ]);

        // MariaDB can retain a unique index as an FK's supporting index. Add
        // explicit non-unique indexes first, before replacing the old constraint.
        if (! Schema::hasIndex('reviews', 'reviews_night_market_daily_lookup_index')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->index('night_market_id', 'reviews_night_market_daily_lookup_index');
            });
        }

        if (! Schema::hasIndex('reviews', 'reviews_user_daily_lookup_index')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->index('user_id', 'reviews_user_daily_lookup_index');
            });
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->date('review_date')->nullable(false)->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            if (Schema::hasIndex('reviews', 'reviews_user_id_food_id_unique')) {
                $table->dropUnique(['user_id', 'food_id']);
            }

            if (Schema::hasIndex('reviews', 'reviews_user_market_unique')) {
                $table->dropUnique('reviews_user_market_unique');
            }

            if (! Schema::hasIndex('reviews', 'reviews_user_food_date_unique')) {
                $table->unique(['user_id', 'food_id', 'review_date'], 'reviews_user_food_date_unique');
            }

            if (! Schema::hasIndex('reviews', 'reviews_user_market_date_unique')) {
                $table->unique(['user_id', 'night_market_id', 'review_date'], 'reviews_user_market_date_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_user_food_date_unique');
            $table->dropUnique('reviews_user_market_date_unique');
            $table->dropIndex('reviews_night_market_daily_lookup_index');
            $table->dropIndex('reviews_user_daily_lookup_index');
            $table->dropColumn('review_date');
        });
    }
};
