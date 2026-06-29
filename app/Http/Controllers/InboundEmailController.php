<?php

namespace App\Http\Controllers;

use App\Enums\UserTypes;
use App\Models\BulkUpload;
use App\Models\BulkUploadItem;
use App\Models\User;
use App\Notifications\InboundEmailReceiptNotification;
use App\Services\ImageService;
use Database\Seeders\UserTagCategorySeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InboundEmailController extends Controller
{
    /**
     * Handle an inbound email webhook from Resend.
     *
     * Resend inbound payload shape:
     * {
     *   "type": "email.received",
     *   "data": {
     *     "from": "Name <email@example.com>",
     *     "to": ["setup@getinked.in"],
     *     "subject": "...",
     *     "attachments": [
     *       { "filename": "tattoo.jpg", "content": "<base64>", "content_type": "image/jpeg", "size": 12345 }
     *     ]
     *   }
     * }
     *
     * Ref: https://resend.com/docs/dashboard/emails/inbound-emails
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->input('data', []);
        $from = $data['from'] ?? null;

        $senderEmail = $this->extractEmail($from);
        if (!$senderEmail) {
            return response()->json(['message' => 'ok'], 200);
        }

        $imageAttachments = array_values(array_filter(
            $data['attachments'] ?? [],
            fn($a) => str_starts_with($a['content_type'] ?? '', 'image/')
        ));

        if (empty($imageAttachments)) {
            Log::info('InboundEmail: no image attachments', ['from' => $senderEmail]);
            return response()->json(['message' => 'ok'], 200);
        }

        $isNewAccount = false;
        $user = User::where('email', $senderEmail)->first();

        if (!$user) {
            $user = $this->createProvisionalArtist($senderEmail, $from);
            $isNewAccount = true;
        }

        $bulkUpload = BulkUpload::create([
            'artist_id' => $user->id,
            'source' => 'email',
            'status' => 'processing',
            'total_images' => count($imageAttachments),
        ]);

        $imageService = app(ImageService::class);
        $processed = 0;

        foreach ($imageAttachments as $index => $attachment) {
            try {
                $ext = $this->extensionFromMime($attachment['content_type'] ?? 'image/jpeg');
                $baseFilename = "tattoo_{$user->id}_" . now()->format('YmdHis') . "_{$index}_" . Str::random(8) . ".{$ext}";

                $image = $imageService->processImage($attachment['content'], $baseFilename);

                BulkUploadItem::create([
                    'bulk_upload_id' => $bulkUpload->id,
                    'image_id' => $image->id,
                    'zip_path' => $attachment['filename'] ?? $baseFilename,
                    'file_size_bytes' => $attachment['size'] ?? null,
                    'is_cataloged' => true,
                    'is_processed' => true,
                    'sort_order' => $index,
                ]);

                $processed++;
            } catch (\Exception $e) {
                Log::error('InboundEmail: failed to process attachment', [
                    'from' => $senderEmail,
                    'attachment' => $attachment['filename'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $bulkUpload->update(['status' => $processed > 0 ? 'ready' : 'failed']);
        $bulkUpload->updateCounts();

        if ($processed > 0) {
            $user->notify(new InboundEmailReceiptNotification($bulkUpload, $processed, $isNewAccount));
        }

        Log::info('InboundEmail: completed', [
            'from' => $senderEmail,
            'processed' => $processed,
            'is_new_account' => $isNewAccount,
            'bulk_upload_id' => $bulkUpload->id,
        ]);

        return response()->json(['message' => 'ok'], 200);
    }

    private function createProvisionalArtist(string $email, ?string $fromHeader): User
    {
        $name = $this->extractName($fromHeader) ?? explode('@', $email)[0];
        $username = $this->generateUniqueUsername($email);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'slug' => $username,
            'password' => Hash::make(Str::random(32)),
            'type_id' => UserTypes::ARTIST_TYPE_ID,
            'location' => '',
            'has_accepted_toc' => false,
            'has_accepted_privacy_policy' => false,
        ]);

        UserTagCategorySeeder::seedForUser($user->id);

        event(new Registered($user));

        return $user;
    }

    private function generateUniqueUsername(string $email): string
    {
        $prefix = explode('@', $email)[0];
        $base = strtolower(preg_replace('/[^a-zA-Z0-9._]/', '', $prefix));
        $base = $base ?: 'artist';
        $base = substr($base, 0, 28);

        $username = $base;
        $counter = 1;
        while (User::where('username', $username)->orWhere('slug', $username)->exists()) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    private function extractEmail(?string $from): ?string
    {
        if (!$from) {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            $email = strtolower(trim($matches[1]));
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }

        $email = strtolower(trim($from));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function extractName(?string $from): ?string
    {
        if (!$from || !str_contains($from, '<')) {
            return null;
        }

        if (preg_match('/^"?([^"<]+?)"?\s*</', $from, $matches)) {
            $name = trim($matches[1]);
            return $name ?: null;
        }

        return null;
    }

    private function extensionFromMime(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
