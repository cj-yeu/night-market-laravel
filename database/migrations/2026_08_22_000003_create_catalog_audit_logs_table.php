<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 40);
            $table->string('summary', 500);
            $table->json('changed_fields')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'created_at'], 'catalog_audit_entity_history_index');
            $table->index('created_at', 'catalog_audit_newest_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_audit_logs');
    }
};
