<?php

namespace App\Console\Commands;

use App\Enums\UserTypes;
use App\Models\BulkUpload;
use App\Models\BulkUploadItem;
use App\Models\InboundEmailLog;
use App\Models\User;
use App\Notifications\InboundEmailReceiptNotification;
use App\Services\ImageService;
use App\Services\SlackService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FetchInboundEmails extends Command
{
    protected $signature = 'email:fetch-inbound
                            {--dry-run : Log what would be processed without saving anything}';

    protected $description = 'Poll the inbound IMAP mailbox and process image attachments into artist portfolios';

    private ImageService $imageService;

    public function handle(ImageService $imageService): int
    {
        $this->imageService = $imageService;

        $host     = config('services.inbound_imap.host');
        $port     = config('services.inbound_imap.port', 993);
        $username = config('services.inbound_imap.username');
        $password = config('services.inbound_imap.password');
        $encryption = config('services.inbound_imap.encryption', 'ssl');

        if (!$host || !$username || !$password) {
            $this->error('INBOUND_IMAP_HOST, INBOUND_IMAP_USERNAME and INBOUND_IMAP_PASSWORD must be set.');
            return Command::FAILURE;
        }

        $flags = match ($encryption) {
            'ssl'  => "/imap/ssl/novalidate-cert",
            'tls'  => "/imap/tls/novalidate-cert",
            default => "/imap/notls",
        };

        $mailbox = "{{$host}:{$port}{$flags}}INBOX";

        if (!function_exists('imap_open')) {
            $this->error('php-imap extension is not loaded. Add "php8.2-imap" to your Dockerfile and rebuild.');
            return Command::FAILURE;
        }

        $connection = @imap_open($mailbox, $username, $password, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

        if (!$connection) {
            $error = imap_last_error();
            $this->error("Could not connect to IMAP: {$error}");
            Log::error('FetchInboundEmails: IMAP connection failed', ['error' => $error]);
            return Command::FAILURE;
        }

        try {
            $this->processMailbox($connection);
        } finally {
            imap_close($connection, CL_EXPUNGE);
        }

        return Command::SUCCESS;
    }

    private function processMailbox($connection): void
    {
        $dryRun = $this->option('dry-run');
        $messageNums = imap_search($connection, 'UNSEEN') ?: [];

        if (empty($messageNums)) {
            $this->line('No unseen messages.');
            return;
        }

        $this->line('Found ' . count($messageNums) . ' unseen message(s).');

        foreach ($messageNums as $msgNum) {
            try {
                $this->processMessage($connection, $msgNum, $dryRun);
            } catch (\Throwable $e) {
                Log::error('FetchInboundEmails: failed to process message', [
                    'msg_num' => $msgNum,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Message #{$msgNum} failed: {$e->getMessage()}");
            }
        }
    }

    private function processMessage($connection, int $msgNum, bool $dryRun): void
    {
        $header  = imap_headerinfo($connection, $msgNum);
        $from    = $header->from[0] ?? null;

        if (!$from) {
            $this->markSeen($connection, $msgNum);
            return;
        }

        $senderEmail = strtolower(trim($from->mailbox . '@' . $from->host));
        $senderName  = isset($from->personal) ? imap_utf8($from->personal) : explode('@', $senderEmail)[0];

        if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $this->markSeen($connection, $msgNum);
            return;
        }

        $imageAttachments = $this->extractImageAttachments($connection, $msgNum);

        if (empty($imageAttachments)) {
            $this->line("  {$senderEmail}: no image attachments, skipping.");
            $this->markSeen($connection, $msgNum);
            return;
        }

        $imageCount = count($imageAttachments);
        $messageUid = (string) imap_uid($connection, $msgNum);

        $this->line("  {$senderEmail}: {$imageCount} image(s) found.");

        if ($dryRun) {
            $this->info("  [dry-run] would process {$imageCount} image(s) for {$senderEmail}");
            $this->markSeen($connection, $msgNum);
            return;
        }

        // Record the message before processing so failures are traceable.
        $log = InboundEmailLog::firstOrCreate(
            ['message_uid' => $messageUid],
            [
                'sender_email' => $senderEmail,
                'sender_name'  => $senderName,
                'image_count'  => $imageCount,
                'is_processed' => false,
            ]
        );

        if ($log->is_processed) {
            $this->line("  {$senderEmail}: already processed (uid {$messageUid}), skipping.");
            $this->markSeen($connection, $msgNum);
            return;
        }

        try {
            $isNewAccount = false;
            $tempPassword = null;
            $user = User::where('email', $senderEmail)->first();

            if (!$user) {
                [$user, $tempPassword] = $this->createProvisionalArtist($senderEmail, $senderName);
                $isNewAccount = true;
                $this->line("  Created provisional account for {$senderEmail}");
                app(SlackService::class)->notifyEmailInboundSignup($user, $imageCount);
            }

            $bulkUpload = BulkUpload::create([
                'artist_id'    => $user->id,
                'source'       => 'email',
                'status'       => 'processing',
                'total_images' => $imageCount,
            ]);

            $processed = 0;

            foreach ($imageAttachments as $index => $attachment) {
                try {
                    $ext = $this->extensionFromMime($attachment['mime']);
                    $baseFilename = "tattoo_{$user->id}_" . now()->format('YmdHis') . "_{$index}_" . Str::random(8) . ".{$ext}";

                    $image = $this->imageService->processImage($attachment['content'], $baseFilename);

                    BulkUploadItem::create([
                        'bulk_upload_id'  => $bulkUpload->id,
                        'image_id'        => $image->id,
                        'zip_path'        => $attachment['filename'] ?? $baseFilename,
                        'file_size_bytes' => $attachment['size'] ?? null,
                        'is_cataloged'    => true,
                        'is_processed'    => true,
                        'sort_order'      => $index,
                    ]);

                    $processed++;
                } catch (\Throwable $e) {
                    Log::error('FetchInboundEmails: failed to store attachment', [
                        'user_id'  => $user->id,
                        'filename' => $attachment['filename'] ?? 'unknown',
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $bulkUpload->update(['status' => $processed > 0 ? 'ready' : 'failed']);
            $bulkUpload->updateCounts();

            if ($processed > 0) {
                $user->notify(new InboundEmailReceiptNotification($bulkUpload, $processed, $isNewAccount, $tempPassword));
            }

            $log->update([
                'is_processed'   => true,
                'processed_at'   => now(),
                'bulk_upload_id' => $bulkUpload->id,
                'error_message'  => null,
            ]);

            $this->info("  {$senderEmail}: {$processed}/{$imageCount} image(s) saved to BulkUpload #{$bulkUpload->id}");

            Log::info('FetchInboundEmails: message processed', [
                'from'           => $senderEmail,
                'processed'      => $processed,
                'is_new_account' => $isNewAccount,
                'bulk_upload_id' => $bulkUpload->id,
            ]);
        } catch (\Throwable $e) {
            $log->update(['error_message' => $e->getMessage()]);

            Log::error('FetchInboundEmails: message processing failed', [
                'from'        => $senderEmail,
                'message_uid' => $messageUid,
                'error'       => $e->getMessage(),
            ]);

            $this->warn("  {$senderEmail}: processing failed — {$e->getMessage()}");
        }

        $this->markSeen($connection, $msgNum);
    }

    private function extractImageAttachments($connection, int $msgNum): array
    {
        $structure = imap_fetchstructure($connection, $msgNum);
        $attachments = [];

        if (!isset($structure->parts)) {
            return $attachments;
        }

        foreach ($structure->parts as $partIndex => $part) {
            $partNum = $partIndex + 1;

            if ($part->type !== TYPEIMAGE) {
                continue;
            }

            $mime = $this->mimeFromPart($part);
            if (!$mime) {
                continue;
            }

            $filename = $this->filenameFromPart($part) ?? "attachment_{$partNum}.jpg";

            $rawBody = imap_fetchbody($connection, $msgNum, (string) $partNum);

            $content = match ($part->encoding) {
                ENCBASE64         => base64_decode($rawBody),
                ENCQUOTEDPRINTABLE => quoted_printable_decode($rawBody),
                default           => $rawBody,
            };

            if (!$content) {
                continue;
            }

            $attachments[] = [
                'filename' => $filename,
                'mime'     => $mime,
                'content'  => base64_encode($content), // ImageService expects base64
                'size'     => strlen($content),
            ];
        }

        return $attachments;
    }

    private function mimeFromPart(object $part): ?string
    {
        $subtypes = [
            'jpeg' => 'image/jpeg',
            'jpg'  => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];

        $subtype = strtolower($part->subtype ?? '');
        return $subtypes[$subtype] ?? (str_starts_with($subtype, 'image/') ? $subtype : null);
    }

    private function filenameFromPart(object $part): ?string
    {
        $sources = [
            $part->dparameters ?? [],
            $part->parameters ?? [],
        ];

        foreach ($sources as $params) {
            foreach ($params as $param) {
                if (strtolower($param->attribute) === 'filename' || strtolower($param->attribute) === 'name') {
                    return imap_utf8($param->value);
                }
            }
        }

        return null;
    }

    private function markSeen($connection, int $msgNum): void
    {
        imap_setflag_full($connection, (string) $msgNum, '\\Seen');
    }

    private function createProvisionalArtist(string $email, string $name): array
    {
        $tempPassword = strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4)) . '-' . rand(1000, 9999);
        $username = $this->generateUniqueUsername($email);

        $user = User::create([
            'name'                        => $name,
            'email'                       => $email,
            'username'                    => $username,
            'slug'                        => $username,
            'password'                    => Hash::make($tempPassword),
            'type_id'                     => UserTypes::ARTIST_TYPE_ID,
            'location'                    => '',
            'has_accepted_toc'            => false,
            'has_accepted_privacy_policy' => false,
            'force_password_reset'        => true,
        ]);

        // Mark verified before firing Registered so SendEmailVerificationNotification skips.
        // email_verified_at may not be in $fillable, so set it explicitly here.
        $user->markEmailAsVerified();

        event(new Registered($user));

        return [$user, $tempPassword];
    }

    private function generateUniqueUsername(string $email): string
    {
        $prefix   = explode('@', $email)[0];
        $base     = strtolower(preg_replace('/[^a-zA-Z0-9._]/', '', $prefix));
        $base     = $base ?: 'artist';
        $base     = substr($base, 0, 28);
        $username = $base;
        $counter  = 1;

        while (User::where('username', $username)->orWhere('slug', $username)->exists()) {
            $username = $base . $counter++;
        }

        return $username;
    }

    private function extensionFromMime(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }
}
