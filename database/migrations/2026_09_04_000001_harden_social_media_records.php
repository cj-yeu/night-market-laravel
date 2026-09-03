<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            // Legacy rows remain nullable: existing duplicates are preserved and can be reviewed manually.
            $table->char('source_url_fingerprint', 64)->nullable()->unique()->after('original_post_url');
            $table->string('rejection_reason', 500)->nullable()->after('approved_at');
            $table->foreignId('rejected_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropUnique(['source_url_fingerprint']);
            $table->dropColumn([
                'source_url_fingerprint',
                'rejection_reason',
                'rejected_by',
                'rejected_at',
            ]);
        });
    }
};
