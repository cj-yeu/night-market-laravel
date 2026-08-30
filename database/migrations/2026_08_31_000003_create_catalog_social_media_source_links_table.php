<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_social_media_source_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('social_media_source_id');
            $table->unsignedBigInteger('catalog_import_proposal_id');
            $table->string('catalog_type', 32);
            $table->unsignedBigInteger('night_market_id')->nullable();
            $table->unsignedBigInteger('stall_id')->nullable();
            $table->unsignedBigInteger('food_id')->nullable();
            $table->timestamps();

            $table->index('social_media_source_id', 'csmsl_source_index');
            $table->index('catalog_import_proposal_id', 'csmsl_proposal_index');
            $table->index('catalog_type', 'csmsl_type_index');
            $table->unique(['social_media_source_id', 'night_market_id'], 'csmsl_source_market_unique');
            $table->unique(['social_media_source_id', 'stall_id'], 'csmsl_source_stall_unique');
            $table->unique(['social_media_source_id', 'food_id'], 'csmsl_source_food_unique');

            $table->foreign('social_media_source_id', 'csmsl_source_fk')
                ->references('id')->on('social_media_sources')->cascadeOnDelete();
            $table->foreign('catalog_import_proposal_id', 'csmsl_proposal_fk')
                ->references('id')->on('catalog_import_proposals')->cascadeOnDelete();
            $table->foreign('night_market_id', 'csmsl_market_fk')
                ->references('id')->on('night_markets')->restrictOnDelete();
            $table->foreign('stall_id', 'csmsl_stall_fk')
                ->references('id')->on('stalls')->restrictOnDelete();
            $table->foreign('food_id', 'csmsl_food_fk')
                ->references('id')->on('foods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_social_media_source_links');
    }
};
