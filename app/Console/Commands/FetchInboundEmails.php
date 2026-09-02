<?php

namespace App\Console\Commands;

use App\Models\InboundEmailLog;
use App\Models\User;
use App\Services\ArtistOnboardingService;
use App\Services\SlackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchInboundEmails extends Command
{
    protected $signature = 'email:fetch-inbound
                            {--dry-run : Log what would be processed without saving anything}';

    protected $description = 'Poll the inbound IMAP mailbox and process image attachments into artist portfolios';

    private ArtistOnboardingService $onboarding;

    public function handle(ArtistOnboardingService $onboarding): int
    {
        $this->onboarding = $onboarding;

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

            // imap_open queues its errors internally and PHP re-emits them as
            // warnings at shutdown, where the @ suppression no longer applies
            // and Laravel turns them into exceptions. Draining the queue keeps
            // an unreachable mailbox to the log line above rather than a
            // reported error every time the schedule runs.
            imap_errors();
            imap_alerts();

            return Command::FAILURE;
        }

        try {
            $this->processMailbox($connection);
        } finally {
            imap_close($connection, CL_EXPUNGE);
            imap_errors();
            imap_alerts();
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
            $existed = User::where('email', $senderEmail)->exists();

            [$user, $bulkUpload, $processed, $isNewAccount] = $this->onboarding->onboard(
                $senderEmail,
                $senderName,
                $imageAttachments,
                'email'
            );

            if (! $existed && $isNewAccount) {
                $this->line("  Created provisional account for {$senderEmail}");
                app(SlackService::class)->notifyEmailInboundSignup($user, $imageCount);
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

}
