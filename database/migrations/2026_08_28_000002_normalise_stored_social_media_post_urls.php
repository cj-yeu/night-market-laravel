<?php

use App\Exceptions\SocialMediaExtractionException;
use App\Services\SocialMediaUrlPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Source URLs are now normalised with per-share tracking parameters removed, so
 * the same post shared twice resolves to one stored URL and one hash. Rows
 * saved before that change still carry their original query string, so this
 * migration re-normalises them and recomputes the de-duplication hash.
 *
 * Hashes are cleared first: a row may normalise onto a hash another row still
 * holds, and the unique index would reject the intermediate state. Rows are
 * then processed oldest-first, so the earliest record of a post keeps the hash
 * and later duplicates are reported and left unprotected rather than deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $policy = app(SocialMediaUrlPolicy::class);

        $records = DB::table('social_media_records')
            ->select('id', 'original_post_url')
            ->orderBy('id')
            ->get();

        DB::table('social_media_records')->update(['source_url_hash' => null]);

        $seen = [];
        $duplicates = [];
        $normalised = 0;

        foreach ($records as $record) {
            try {
                $url = $policy->inspectSourceUrl((string) $record->original_post_url)['url'];
            } catch (SocialMediaExtractionException) {
                // An unsupported legacy URL cannot be normalised; it is left as
                // it is and simply stays outside duplicate protection.
                continue;
            }

            $hash = hash('sha256', $url);

            if (isset($seen[$hash])) {
                $duplicates[] = "record #{$record->id} duplicates #{$seen[$hash]} — {$url}";

                DB::table('social_media_records')
                    ->where('id', $record->id)
                    ->update(['original_post_url' => $url]);

                continue;
            }

            $seen[$hash] = $record->id;

            if ($url !== $record->original_post_url) {
                $normalised++;
            }

            DB::table('social_media_records')
                ->where('id', $record->id)
                ->update([
                    'original_post_url' => $url,
                    'source_url_hash' => $hash,
                ]);
        }

        echo "  normalised {$normalised} stored post URL(s)\n";

        foreach ($duplicates as $duplicate) {
            echo "  [warning] duplicate revealed by normalisation, left without a hash: {$duplicate}\n";
        }
    }

    public function down(): void
    {
        // Original query strings are not retained, so the previous URLs cannot
        // be restored. Nothing is undone here on purpose.
    }
};
