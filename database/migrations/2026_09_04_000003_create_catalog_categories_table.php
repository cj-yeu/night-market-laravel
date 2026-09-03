<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('category_type', 16);
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['category_type', 'normalized_name'], 'catalog_categories_type_normalized_unique');
            $table->index(['category_type', 'is_active', 'name'], 'catalog_categories_type_active_name_index');
        });

        $now = now();
        $insert = function (string $type, ?string $value) use ($now): void {
            if (! is_string($value)) {
                return;
            }

            $name = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
            $normalizedName = mb_strtolower($name);

            if ($name === '' || mb_strlen($name) > 100 || mb_strlen($normalizedName) > 100) {
                return;
            }

            DB::table('catalog_categories')->insertOrIgnore([
                'category_type' => $type,
                'name' => $name,
                'normalized_name' => $normalizedName,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };

        foreach (DB::table('stalls')->whereNotNull('category')->orderBy('id')->pluck('category') as $category) {
            $insert('stall', $category);
        }

        foreach (DB::table('foods')->whereNotNull('category')->orderBy('id')->pluck('category') as $category) {
            $insert('food', $category);
        }

        foreach (['Food', 'Beverage', 'Dessert', 'Snack', 'Local Cuisine'] as $category) {
            $insert('food', $category);
        }

        foreach (['Food Stall', 'Beverage Stall', 'Dessert Stall', 'Snack Stall'] as $category) {
            $insert('stall', $category);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_categories');
    }
};
