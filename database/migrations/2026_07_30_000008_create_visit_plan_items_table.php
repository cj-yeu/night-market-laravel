<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_plan_id')->constrained()->cascadeOnDelete();
            $table->string('item_type');
            $table->string('item_name');
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_plan_items');
    }
};
