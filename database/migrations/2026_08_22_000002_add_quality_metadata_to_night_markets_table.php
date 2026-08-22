<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('night_markets', function (Blueprint $table) {
            $table->string('source_url')->nullable()->after('description');
            $table->date('verified_at')->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('night_markets', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'verified_at']);
        });
    }
};
