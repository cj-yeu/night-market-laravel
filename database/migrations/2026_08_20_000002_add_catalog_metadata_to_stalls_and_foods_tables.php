<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stalls', function (Blueprint $table) {
            $table->string('category')->nullable()->after('description');
            $table->string('halal_status')->default('unknown')->after('category');
            $table->string('halal_evidence_url')->nullable()->after('halal_status');
            $table->text('halal_notes')->nullable()->after('halal_evidence_url');
            $table->string('source_url')->nullable()->after('halal_notes');
            $table->date('verified_at')->nullable()->after('source_url');
        });

        Schema::table('foods', function (Blueprint $table) {
            $table->decimal('price_min', 10, 2)->nullable()->after('category');
            $table->decimal('price_max', 10, 2)->nullable()->after('price_min');
            $table->string('price_display')->nullable()->after('price_max');
            $table->text('recommendation_reason')->nullable()->after('is_must_try');
            $table->string('source_url')->nullable()->after('recommendation_reason');
            $table->date('price_checked_at')->nullable()->after('source_url');
            $table->date('verified_at')->nullable()->after('price_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropColumn([
                'price_min',
                'price_max',
                'price_display',
                'recommendation_reason',
                'source_url',
                'price_checked_at',
                'verified_at',
            ]);
        });

        Schema::table('stalls', function (Blueprint $table) {
            $table->dropColumn([
                'category',
                'halal_status',
                'halal_evidence_url',
                'halal_notes',
                'source_url',
                'verified_at',
            ]);
        });
    }
};
