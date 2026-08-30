<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes');
            // DATETIME avoids legacy MySQL/MariaDB implicit TIMESTAMP defaults.
            $table->dateTime('connected_at');
            $table->timestamps();
        });

        Schema::create('google_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_plan_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('google_event_id', 255);
            $table->text('google_event_url')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_events');
        Schema::dropIfExists('google_calendar_connections');
    }
};
