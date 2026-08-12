<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('visit_plan_items', 'stall_id')) {
            Schema::table('visit_plan_items', function (Blueprint $table) {
                $table->foreignId('stall_id')
                    ->nullable()
                    ->after('visit_plan_id')
                    ->constrained('stalls')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('visit_plan_items', 'food_id')) {
            Schema::table('visit_plan_items', function (Blueprint $table) {
                $table->foreignId('food_id')
                    ->nullable()
                    ->after('stall_id')
                    ->constrained('foods')
                    ->nullOnDelete();
            });

            return;
        }

        Schema::table('visit_plan_items', function (Blueprint $table) {
            $table->foreign('food_id')
                ->references('id')
                ->on('foods')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_plan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('food_id');
            $table->dropConstrainedForeignId('stall_id');
        });
    }
};
