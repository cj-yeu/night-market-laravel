<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stall_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_must_try')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['stall_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foods');
    }
};
