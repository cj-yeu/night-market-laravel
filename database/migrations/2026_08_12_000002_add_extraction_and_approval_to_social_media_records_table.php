<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('mentioned_food_name');
            $table->json('extracted_hashtags')->nullable()->after('status');
            $table->json('extracted_location_mentions')->nullable()->after('extracted_hashtags');
            $table->json('extracted_market_mentions')->nullable()->after('extracted_location_mentions');
            $table->json('extracted_food_mentions')->nullable()->after('extracted_market_mentions');
            $table->foreignId('approved_by')
                ->nullable()
                ->after('extracted_food_mentions')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'status',
                'extracted_hashtags',
                'extracted_location_mentions',
                'extracted_market_mentions',
                'extracted_food_mentions',
                'approved_at',
            ]);
        });
    }
};
