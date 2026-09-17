<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_operating_days', function (Blueprint $table) {
            $table->time('opening_time')->nullable()->change();
            $table->time('closing_time')->nullable()->change();
        });

        Schema::table('catalog_social_media_source_links', function (Blueprint $table) {
            $table->text('evidence_text')->nullable()->after('catalog_type');
            $table->string('evidence_method', 32)->nullable()->after('evidence_text');
            $table->date('source_published_at')->nullable()->after('evidence_method');
        });

        Schema::table('night_markets', function (Blueprint $table) {
            $table->dropUnique('night_markets_catalog_identity_hash_unique');
            $table->index('catalog_identity_hash', 'night_markets_catalog_identity_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('night_markets', function (Blueprint $table) {
            $table->dropIndex('night_markets_catalog_identity_hash_index');
            $table->unique('catalog_identity_hash', 'night_markets_catalog_identity_hash_unique');
        });

        Schema::table('catalog_social_media_source_links', function (Blueprint $table) {
            $table->dropColumn(['evidence_text', 'evidence_method', 'source_published_at']);
        });

        Schema::table('market_operating_days', function (Blueprint $table) {
            $table->time('opening_time')->nullable(false)->change();
            $table->time('closing_time')->nullable(false)->change();
        });
    }
};
