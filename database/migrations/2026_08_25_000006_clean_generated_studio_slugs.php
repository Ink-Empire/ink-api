<?php

use App\Services\StudioService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Studios imported from Google Places were slugged as name plus six random
 * characters, giving public URLs like /studios/treasure-ink-tattoo-studio-Tyx4Ue.
 * Imports now go through StudioService::generateSlug, which suffixes only on a
 * real collision, so the existing rows are brought in line.
 *
 * Only slugs that are exactly the old generated shape are touched: the studio's
 * name slug, a hyphen, then six alphanumerics. A slug a person chose is left
 * alone even if it happens to end in six characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        $service = app(StudioService::class);

        foreach (DB::table('studios')->select('id', 'name', 'slug')->get() as $studio) {
            if (! $studio->slug || ! $studio->name) {
                continue;
            }

            $expectedPrefix = Str::slug($studio->name).'-';

            if (! str_starts_with($studio->slug, $expectedPrefix)) {
                continue;
            }

            $suffix = substr($studio->slug, strlen($expectedPrefix));

            if (! preg_match('/^[A-Za-z0-9]{6}$/', $suffix)) {
                continue;
            }

            $clean = $service->generateSlug($studio->name, $studio->id);

            if ($clean !== $studio->slug) {
                DB::table('studios')->where('id', $studio->id)->update(['slug' => $clean]);
            }
        }
    }

    public function down(): void
    {
        // The random suffixes carried no meaning, so there is nothing to restore.
    }
};
