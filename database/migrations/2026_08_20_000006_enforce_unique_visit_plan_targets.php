<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_plan_items', function (Blueprint $table) {
            $table->unique(['visit_plan_id', 'stall_id'], 'visit_plan_items_plan_stall_unique');
            $table->unique(['visit_plan_id', 'food_id'], 'visit_plan_items_plan_food_unique');
        });
    }

    public function down(): void
    {
        Schema::table('visit_plan_items', function (Blueprint $table) {
            $table->dropUnique('visit_plan_items_plan_stall_unique');
            $table->dropUnique('visit_plan_items_plan_food_unique');
        });
    }
};
