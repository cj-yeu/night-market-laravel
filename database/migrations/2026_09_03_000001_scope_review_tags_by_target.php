<?php

use App\Models\ReviewTag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_tags', function (Blueprint $table) {
            $table->string('target_type', 16)->nullable()->after('name');
            $table->index('target_type', 'review_tags_target_type_index');
        });

        DB::table('review_tags')
            ->whereIn('name', ReviewTag::MARKET_NAMES)
            ->update(['target_type' => ReviewTag::TARGET_MARKET]);

        DB::table('review_tags')
            ->whereIn('name', ReviewTag::FOOD_NAMES)
            ->update(['target_type' => ReviewTag::TARGET_FOOD]);

        foreach (ReviewTag::MARKET_NAMES as $name) {
            DB::table('review_tags')->insertOrIgnore([
                'name' => $name,
                'target_type' => ReviewTag::TARGET_MARKET,
            ]);
        }

        foreach (ReviewTag::FOOD_NAMES as $name) {
            DB::table('review_tags')->insertOrIgnore([
                'name' => $name,
                'target_type' => ReviewTag::TARGET_FOOD,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('review_tags', function (Blueprint $table) {
            $table->dropIndex('review_tags_target_type_index');
            $table->dropColumn('target_type');
        });
    }
};
