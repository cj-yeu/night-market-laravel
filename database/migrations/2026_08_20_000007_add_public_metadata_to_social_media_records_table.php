<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->string('extracted_title', 500)->nullable()->after('original_post_url');
            $table->string('external_image_url', 2048)->nullable()->after('content_summary');
            $table->string('extraction_status', 20)->default('manual')->after('external_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->dropColumn(['extracted_title', 'external_image_url', 'extraction_status']);
        });
    }
};
