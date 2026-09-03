<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_import_proposals', function (Blueprint $table) {
            $table->string('extraction_status', 32)->default('pending')->after('failure_code');
            $table->string('extraction_failure_code', 100)->nullable()->after('extraction_status');
            $table->string('extraction_model', 100)->nullable()->after('extraction_failure_code');
            $table->char('extraction_input_hash', 64)->nullable()->after('extraction_model');
            $table->timestamp('extracted_at')->nullable()->after('extraction_input_hash');

            $table->index('extraction_input_hash', 'cip_extraction_input_hash_index');
        });

        Schema::create('catalog_import_proposal_markets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_import_proposal_id');
            $table->unsignedBigInteger('matched_night_market_id')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->text('description')->nullable();
            $table->string('evidence_text', 1000)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->timestamps();

            $table->unique('catalog_import_proposal_id', 'cipm_proposal_unique');
            $table->foreign('catalog_import_proposal_id', 'cipm_proposal_fk')
                ->references('id')->on('catalog_import_proposals')->cascadeOnDelete();
            $table->foreign('matched_night_market_id', 'cipm_market_fk')
                ->references('id')->on('night_markets')->nullOnDelete();
        });

        Schema::create('catalog_import_proposal_operating_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_import_proposal_market_id');
            $table->string('day_of_week', 16);
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            $table->string('evidence_text', 1000)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['catalog_import_proposal_market_id', 'day_of_week'], 'cipod_market_day_unique');
            $table->foreign('catalog_import_proposal_market_id', 'cipod_market_fk')
                ->references('id')->on('catalog_import_proposal_markets')->cascadeOnDelete();
        });

        Schema::create('catalog_import_proposal_stalls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_import_proposal_market_id');
            $table->unsignedBigInteger('matched_stall_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('halal_status', 32)->default('unknown');
            $table->string('evidence_text', 1000)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['catalog_import_proposal_market_id', 'display_order'], 'cips_market_order_index');
            $table->foreign('catalog_import_proposal_market_id', 'cips_market_fk')
                ->references('id')->on('catalog_import_proposal_markets')->cascadeOnDelete();
            $table->foreign('matched_stall_id', 'cips_stall_fk')
                ->references('id')->on('stalls')->nullOnDelete();
        });

        Schema::create('catalog_import_proposal_foods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_import_proposal_stall_id');
            $table->unsignedBigInteger('matched_food_id')->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('price_display')->nullable();
            $table->decimal('price_min', 10, 2)->nullable();
            $table->decimal('price_max', 10, 2)->nullable();
            $table->boolean('is_must_try')->default(false);
            $table->string('evidence_text', 1000)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['catalog_import_proposal_stall_id', 'display_order'], 'cipf_stall_order_index');
            $table->foreign('catalog_import_proposal_stall_id', 'cipf_stall_fk')
                ->references('id')->on('catalog_import_proposal_stalls')->cascadeOnDelete();
            $table->foreign('matched_food_id', 'cipf_food_fk')
                ->references('id')->on('foods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_proposal_foods');
        Schema::dropIfExists('catalog_import_proposal_stalls');
        Schema::dropIfExists('catalog_import_proposal_operating_days');
        Schema::dropIfExists('catalog_import_proposal_markets');

        Schema::table('catalog_import_proposals', function (Blueprint $table) {
            $table->dropIndex('cip_extraction_input_hash_index');
            $table->dropColumn([
                'extraction_status',
                'extraction_failure_code',
                'extraction_model',
                'extraction_input_hash',
                'extracted_at',
            ]);
        });
    }
};
