<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('night_markets', function (Blueprint $table) {
            $table->string('catalog_code', 64)->nullable()->unique()->after('id');
        });

        Schema::table('stalls', function (Blueprint $table) {
            $table->string('catalog_code', 64)->nullable()->unique()->after('id');
        });

        Schema::table('foods', function (Blueprint $table) {
            $table->string('catalog_code', 64)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropUnique(['catalog_code']);
            $table->dropColumn('catalog_code');
        });

        Schema::table('stalls', function (Blueprint $table) {
            $table->dropUnique(['catalog_code']);
            $table->dropColumn('catalog_code');
        });

        Schema::table('night_markets', function (Blueprint $table) {
            $table->dropUnique(['catalog_code']);
            $table->dropColumn('catalog_code');
        });
    }
};
