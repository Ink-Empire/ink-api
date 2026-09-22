<?php

namespace Tests\Unit;

use App\Models\BulkUpload;
use App\Models\User;
use App\Notifications\InboundEmailReceiptNotification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

/**
 * The review link is the only thing this email asks the artist to do, and it
 * pointed at /dashboard/uploads/{id} for as long as the feature has existed.
 * /dashboard is a flat page with no nested routes, so every artist who
 * followed it got a 404 on their first contact with the platform.
 *
 * Nothing caught it because no test asserted where the link went and a wrong
 * URL looks identical to a right one until somebody clicks it.
 */
class InboundEmailReceiptLinkTest extends TestCase
{
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The user observer notifies Slack on create, which runs inline on the
        // sync queue and reaches out over the network.
        Queue::fake();
    }

    public function test_the_review_link_points_at_the_review_page(): void
    {
        config(['app.frontend_url' => 'https://getinked.in']);

        $artist = User::factory()->asArtist()->create();
        $upload = BulkUpload::create([
            'artist_id' => $artist->id,
            'source' => 'email',
            'status' => 'ready',
            'total_images' => 1,
        ]);

        $mail = (new InboundEmailReceiptNotification($upload, 1, true, 'TEMP-PASS-1234'))
            ->toMail($artist);

        $this->assertSame(
            "https://getinked.in/bulk-upload/{$upload->id}",
            $mail->viewData['reviewUrl']
        );
    }

    /**
     * Five notifications built links under /dashboard/, and every one of them
     * 404s, because dashboard.tsx is a flat page with no nested routes. They
     * went unnoticed for as long as they existed, since a wrong URL in an
     * email looks exactly like a right one until somebody clicks it.
     *
     * This reads the source rather than the rendered mail so a new one is
     * caught the moment it is written, without needing a test per
     * notification. If the frontend ever grows real nested dashboard routes,
     * delete this.
     */
    public function test_no_notification_links_under_the_flat_dashboard_page(): void
    {
        $offenders = [];

        // Matches a quoted path so the comments explaining this bug, which
        // necessarily name the bad URLs, are not themselves flagged.
        foreach (glob(app_path('Notifications/*.php')) as $file) {
            if (preg_match('#[\'"]/dashboard/#', file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_login_link_points_at_the_login_page(): void
    {
        config(['app.frontend_url' => 'https://getinked.in']);

        $artist = User::factory()->asArtist()->create();
        $upload = BulkUpload::create([
            'artist_id' => $artist->id,
            'source' => 'admin',
            'status' => 'ready',
            'total_images' => 1,
        ]);

        $mail = (new InboundEmailReceiptNotification($upload, 1, true, 'TEMP-PASS-1234'))
            ->toMail($artist);

        $this->assertSame('https://getinked.in/login', $mail->viewData['loginUrl']);
    }
}
