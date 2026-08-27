<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval already records who approved a record and when. Rejection recorded
 * only the status, so a rejected record carried no explanation and no
 * accountability. These columns make the two moderation outcomes symmetrical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->string('rejection_reason', 500)->nullable()->after('approved_at');
            $table->foreignId('rejected_by')
                ->nullable()
                ->after('rejection_reason')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejection_reason', 'rejected_at']);
        });
    }
};
