<?php

namespace Database\Seeders;

use App\Enums\ArtistTattooApprovalStatus;
use App\Enums\PostType;
use App\Enums\UserTypes;
use App\Models\Image;
use App\Models\Style;
use App\Models\Tattoo;
use App\Models\TattooLead;
use App\Models\User;
use Illuminate\Database\Seeder;

class PostTypeSeeder extends Seeder
{
    /**
     * Marker string embedded in description of every test record so we can
     * find and remove them later. Keep it short and unlikely to collide.
     */
    public const MARKER = '#pttest';

    /**
     * Number of records to create. Override from the command via env.
     */
    protected int $count;

    public function __construct()
    {
        $this->count = (int) (env('POST_TYPE_SEED_COUNT') ?: 20);
    }

    public function run(): void
    {
        // Always clean previous runs first
        self::cleanup();

        $artists = User::where('type_id', UserTypes::ARTIST_TYPE_ID)->pluck('id')->all();
        $clients = User::where('type_id', UserTypes::CLIENT_TYPE_ID)->pluck('id')->all();
        $imageIds = Image::pluck('id')->all();
        $styleIds = Style::pluck('id')->all();

        if (empty($artists) || empty($clients) || empty($imageIds) || empty($styleIds)) {
            $this->command?->warn('PostTypeSeeder: need at least one artist, client, image, and style. Skipping.');
            return;
        }

        // Roughly half flash, half seeking
        $flashCount = (int) floor($this->count / 2);
        $seekingCount = $this->count - $flashCount;

        $created = ['flash' => [], 'seeking' => []];

        for ($i = 0; $i < $flashCount; $i++) {
            $created['flash'][] = $this->createFlash($artists, $imageIds, $styleIds, $i);
        }

        for ($i = 0; $i < $seekingCount; $i++) {
            $created['seeking'][] = $this->createSeeking($clients, $imageIds, $styleIds, $i);
        }

        foreach (array_merge($created['flash'], $created['seeking']) as $tattoo) {
            $tattoo->searchable();
        }

        $this->command?->info(sprintf(
            'PostTypeSeeder: created %d flash + %d seeking posts (marker: %s)',
            count($created['flash']),
            count($created['seeking']),
            self::MARKER
        ));
    }

    /**
     * Remove all test records created by this seeder.
     */
    public static function cleanup(): array
    {
        $tattoos = Tattoo::where('description', 'like', '%'.self::MARKER.'%')->get();
        $leadIds = $tattoos->pluck('tattoo_lead_id')->filter()->unique()->values()->all();

        $tattooCount = $tattoos->count();
        foreach ($tattoos as $t) {
            $t->searchable(); // ensure indexed state updates
            $t->forceDelete();
        }

        $leadCount = 0;
        if (!empty($leadIds)) {
            $leadCount = TattooLead::whereIn('id', $leadIds)->delete();
        }

        return ['tattoos' => $tattooCount, 'leads' => $leadCount];
    }

    private function createFlash(array $artists, array $imageIds, array $styleIds, int $idx): Tattoo
    {
        $titles = [
            'Neo-Trad Snake Flash', 'Ornamental Moth Flash', 'Black Dagger Flash',
            'Traditional Rose Flash', 'Fine Line Botanical Flash', 'Japanese Koi Flash',
            'Dotwork Moon Flash', 'Blackwork Raven Flash', 'Cherry Blossom Flash',
            'Geometric Wolf Flash',
        ];
        $sizes = ['palm-sized', '3-inch', '4-inch', '5-inch', 'forearm', 'half-sleeve'];
        $prices = [150, 200, 250, 300, 400, 500, 650, 800];

        $artistId = $artists[array_rand($artists)];
        $imageId = $imageIds[array_rand($imageIds)];
        $styleId = $styleIds[array_rand($styleIds)];
        $title = $titles[$idx % count($titles)];

        $tattoo = Tattoo::create([
            'artist_id' => $artistId,
            'uploaded_by_user_id' => $artistId,
            'approval_status' => ArtistTattooApprovalStatus::APPROVED,
            'is_visible' => true,
            'is_demo' => false,
            'post_type' => PostType::FLASH,
            'flash_price' => $prices[array_rand($prices)],
            'flash_size' => $sizes[array_rand($sizes)],
            'primary_image_id' => $imageId,
            'title' => $title,
            'description' => 'Available as flash. Ready to tattoo. '.self::MARKER,
            'primary_style_id' => $styleId,
        ]);
        $tattoo->images()->attach([$imageId]);
        $tattoo->styles()->attach([$styleId]);

        return $tattoo;
    }

    private function createSeeking(array $clients, array $imageIds, array $styleIds, int $idx): Tattoo
    {
        $titles = [
            'Looking for a geometric sleeve', 'Wanted: dotwork mandala',
            'Seeking fine line floral', 'Looking for a traditional eagle',
            'Wanted: blackwork chest piece', 'Seeking Japanese half-sleeve',
            'Looking for a watercolor piece', 'Wanted: portrait artist',
            'Seeking neo-traditional', 'Looking for a cover-up artist',
        ];
        $timings = ['week', 'month', 'year'];

        $clientId = $clients[array_rand($clients)];
        $imageId = $imageIds[array_rand($imageIds)];
        $styleId = $styleIds[array_rand($styleIds)];
        $title = $titles[$idx % count($titles)];

        $lead = TattooLead::create([
            'user_id' => $clientId,
            'timing' => $timings[array_rand($timings)],
            'interested_by' => now()->addMonths(rand(1, 6)),
            'allow_artist_contact' => true,
            'style_ids' => [$styleId],
            'description' => $title.'. '.self::MARKER,
            'is_active' => true,
        ]);

        $tattoo = Tattoo::create([
            'uploaded_by_user_id' => $clientId,
            'approval_status' => ArtistTattooApprovalStatus::USER_ONLY,
            'is_visible' => true,
            'is_demo' => false,
            'post_type' => PostType::SEEKING,
            'tattoo_lead_id' => $lead->id,
            'primary_image_id' => $imageId,
            'title' => $title,
            'description' => $title.'. '.self::MARKER,
            'primary_style_id' => $styleId,
        ]);
        $tattoo->images()->attach([$imageId]);
        $tattoo->styles()->attach([$styleId]);

        return $tattoo;
    }
}
