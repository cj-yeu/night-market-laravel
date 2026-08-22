<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->renameColumn('post_url', 'original_post_url');
            $table->renameColumn('content', 'content_summary');
            $table->renameColumn('post_date', 'posted_date');
        });

        Schema::table('social_media_records', function (Blueprint $table) {
            $table->foreignId('night_market_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('food_id')
                ->nullable()
                ->after('night_market_id')
                ->constrained('foods')
                ->nullOnDelete();
            $table->unsignedBigInteger('likes')->default(0)->after('posted_date');
            $table->unsignedBigInteger('comments')->default(0)->after('likes');
            $table->unsignedBigInteger('shares')->default(0)->after('comments');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('food_id');
            $table->dropConstrainedForeignId('night_market_id');
            $table->dropColumn(['likes', 'comments', 'shares']);
        });

        Schema::table('social_media_records', function (Blueprint $table) {
            $table->renameColumn('original_post_url', 'post_url');
            $table->renameColumn('content_summary', 'content');
            $table->renameColumn('posted_date', 'post_date');
        });
    }
};
