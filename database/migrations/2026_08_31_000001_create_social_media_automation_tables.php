<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_sources', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 32);
            $table->string('canonical_url', 2048);
            $table->char('url_fingerprint', 64);
            $table->string('external_content_id', 255)->nullable();
            $table->string('title', 500)->nullable();
            $table->text('description_excerpt')->nullable();
            $table->string('creator_name', 255)->nullable();
            $table->string('thumbnail_url', 2048)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('metadata_provider', 100)->nullable();
            $table->string('metadata_status', 32)->default('pending');
            $table->string('failure_code', 100)->nullable();
            $table->timestamp('metadata_fetched_at')->nullable();
            $table->timestamps();

            $table->unique('url_fingerprint');
            $table->unique(['platform', 'external_content_id']);
            $table->index(['metadata_status', 'created_at']);
        });

        Schema::create('catalog_import_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_media_source_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->foreignId('matched_night_market_id')
                ->nullable()
                ->constrained('night_markets')
                ->nullOnDelete();
            $table->foreignId('matched_stall_id')
                ->nullable()
                ->constrained('stalls')
                ->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->timestamps();

            $table->unique(['social_media_source_id', 'revision']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_proposals');
        Schema::dropIfExists('social_media_sources');
    }
};
