<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_import_proposals', function (Blueprint $table) {
            $table->json('review_metadata_snapshot')->nullable()->after('review_note');
            $table->char('review_input_hash', 64)->nullable()->after('review_metadata_snapshot');
            $table->char('extraction_attempt_token', 36)->nullable()->after('extraction_input_hash');
            $table->timestamp('extraction_attempt_started_at')->nullable()->after('extraction_attempt_token');
        });

        Schema::table('night_markets', function (Blueprint $table) {
            $table->char('catalog_identity_hash', 64)->nullable()->after('state');
            $table->unique('catalog_identity_hash', 'night_markets_catalog_identity_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('night_markets', function (Blueprint $table) {
            $table->dropUnique('night_markets_catalog_identity_hash_unique');
        });

        Schema::table('night_markets', function (Blueprint $table) {
            $table->dropColumn('catalog_identity_hash');
        });

        Schema::table('catalog_import_proposals', function (Blueprint $table) {
            $table->dropColumn([
                'extraction_attempt_started_at',
                'extraction_attempt_token',
                'review_input_hash',
                'review_metadata_snapshot',
            ]);
        });
    }
};
