<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_records', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            $table->string('post_url', 2048)->nullable();
            $table->longText('content');
            $table->date('post_date');
            $table->unsignedBigInteger('engagement_count')->default(0);
            $table->string('mentioned_market_name')->nullable();
            $table->string('mentioned_food_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_records');
    }
};
