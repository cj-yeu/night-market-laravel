<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL cannot place a unique index on the full `original_post_url` column:
 * InnoDB caps a single index key at 3072 bytes and utf8mb4 spends up to 4 bytes
 * per character, so varchar(2048) needs up to 8192. A prefix index would only
 * compare the first ~191 characters and would report false duplicates for long
 * URLs that share a prefix, so the fixed-width SHA-256 of the normalised URL is
 * indexed instead.
 *
 * Existing rows are never deleted. The first row of each duplicate group keeps
 * its hash; later duplicates are left NULL (MySQL allows repeated NULLs in a
 * unique index) and reported, so an administrator can review them by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->char('source_url_hash', 64)->nullable()->after('original_post_url');
        });

        $this->backfillHashes();

        Schema::table('social_media_records', function (Blueprint $table) {
            $table->unique('source_url_hash');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_records', function (Blueprint $table) {
            $table->dropUnique(['source_url_hash']);
            $table->dropColumn('source_url_hash');
        });
    }

    private function backfillHashes(): void
    {
        $seen = [];
        $duplicates = [];

        $records = DB::table('social_media_records')
            ->select('id', 'original_post_url')
            ->orderBy('id')
            ->get();

        foreach ($records as $record) {
            $hash = $this->normalisedHash($record->original_post_url);

            if ($hash === null) {
                continue;
            }

            if (isset($seen[$hash])) {
                $duplicates[] = "record #{$record->id} duplicates #{$seen[$hash]} — {$record->original_post_url}";

                continue;
            }

            $seen[$hash] = $record->id;

            DB::table('social_media_records')
                ->where('id', $record->id)
                ->update(['source_url_hash' => $hash]);
        }

        foreach ($duplicates as $duplicate) {
            echo "  [warning] pre-existing duplicate left without a hash: {$duplicate}\n";
        }
    }

    private function normalisedHash(?string $url): ?string
    {
        $url = (string) preg_replace('/#.*\z/s', '', trim((string) $url));

        return $url === '' ? null : hash('sha256', $url);
    }
};
