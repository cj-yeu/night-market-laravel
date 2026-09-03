<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_events', function (Blueprint $table) {
            $table->string('sync_status', 32)->default('synced')->after('payload_hash');
            $table->string('last_sync_error_code', 100)->nullable()->after('sync_status');
            $table->timestamp('last_sync_failed_at')->nullable()->after('last_synced_at');
            $table->index(['sync_status', 'last_sync_failed_at'], 'google_calendar_events_sync_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_events', function (Blueprint $table) {
            $table->dropIndex('google_calendar_events_sync_status_index');
            $table->dropColumn(['sync_status', 'last_sync_error_code', 'last_sync_failed_at']);
        });
    }
};
