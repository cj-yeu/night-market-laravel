<?php

use App\Models\ReviewTag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
        });

        Schema::create('review_review_tag', function (Blueprint $table) {
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['review_id', 'review_tag_id']);
        });

        DB::table('review_tags')->insert(array_map(fn (string $name) => ['name' => $name], ReviewTag::NAMES));
    }

    public function down(): void
    {
        Schema::dropIfExists('review_review_tag');
        Schema::dropIfExists('review_tags');
    }
};
