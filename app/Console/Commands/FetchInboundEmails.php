<?php

namespace App\Console\Commands;

use App\Models\InboundEmailLog;
use App\Models\User;
use App\Services\ArtistOnboardingService;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchInboundEmails extends Command
{
    protected $signature = 'email:fetch-inbound
                            {--dry-run : Log what would be processed without saving anything}';

    protected $description = 'Poll the inbound IMAP mailbox and process image attachments into artist portfolios';

    private const OUTAGE_SINCE_KEY = 'inbound_email:unreachable_since';

    private const OUTAGE_ALERTED_KEY = 'inbound_email:outage_alerted';

    /** @var array<int, string> */
    private array $lastErrors = [];

    /** @var array<int, string> */
    private array $lastAlerts = [];

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

        $connection = $this->connect($mailbox, $username, $password);

        if (!$connection) {
            $errors = $this->lastErrors;
            $error = $errors ? end($errors) : 'Unknown IMAP error';

            $this->error("Could not connect to IMAP: {$error}");
            Log::error('FetchInboundEmails: IMAP connection failed', [
                'error' => $error,
                'all_errors' => $errors,
                'alerts' => $this->lastAlerts,
            ]);
            $this->reportOutage((string) $error);

            return Command::FAILURE;
        }

        $this->reportRecovery();

        try {
            $this->processMailbox($connection);
        } finally {
            imap_close($connection, CL_EXPUNGE);
            imap_errors();
            imap_alerts();
        }

        return Command::SUCCESS;
    }

    /**
     * Opens the mailbox, trying more than once before calling it a failure.
     *
     * Exactly one run an hour, the one at the top of the hour, has its
     * connection refused, while the nineteen either side of it connect fine
     * from the same host with the same credentials. The cause is not known.
     * What is known is that the refusal does not survive a second attempt
     * moments later, and that a mailbox which is genuinely unreachable fails
     * both, so retrying cannot hide a real outage.
     *
     * Returns the connection, or false with the errors from the final attempt
     * left on the command for the caller to report.
     */
    private function connect(string $mailbox, string $username, string $password)
    {
        $attempts = max(1, (int) config('services.inbound_imap.connect_attempts', 2));
        $pause = max(0, (int) config('services.inbound_imap.retry_seconds', 5));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $connection = $this->openMailbox($mailbox, $username, $password);

            // c-client queues every error it hit on the way down, and
            // imap_last_error() returns only the final entry. Draining also
            // matters for its own sake: PHP re-emits these as warnings at
            // shutdown, where the @ suppression no longer applies and Laravel
            // turns them into exceptions. Drained per attempt so a reported
            // failure carries only its own errors.
            $this->lastErrors = imap_errors() ?: [];
            $this->lastAlerts = imap_alerts() ?: [];

            if ($connection) {
                if ($attempt > 1) {
                    Log::info('FetchInboundEmails: the mailbox connected on a retry', [
                        'attempt' => $attempt,
                    ]);
                }

                return $connection;
            }

            if ($attempt < $attempts) {
                Log::debug('FetchInboundEmails: mailbox refused the connection, retrying', [
                    'attempt' => $attempt,
                    'errors' => $this->lastErrors,
                ]);

                sleep($pause);
            }
        }

        return false;
    }

    /**
     * The bare connection attempt, separated so a test can drive the retry
     * without reaching a real mail server.
     */
    protected function openMailbox(string $mailbox, string $username, string $password)
    {
        return @imap_open($mailbox, $username, $password, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    }

    /**
     * Tells the ops channel once when the mailbox has been unreachable for
     * long enough to mean something, then stays quiet until it recovers.
     *
     * An unreachable mailbox is silent by nature: artists email their work in
     * and hear nothing back, and the only trace is a log line. This ran broken
     * for 26 days before anyone noticed. Alerting on the first failed run
     * instead would post every three minutes, which is the same as not
     * alerting at all.
     */
    private function reportOutage(string $error): void
    {
        $threshold = (int) config('services.inbound_imap.outage_alert_minutes', 30);

        try {
            $failingSince = Cache::get(self::OUTAGE_SINCE_KEY);

            if (! $failingSince) {
                Cache::put(self::OUTAGE_SINCE_KEY, now()->toIso8601String(), now()->addDay());

                return;
            }

            if (Cache::get(self::OUTAGE_ALERTED_KEY)) {
                return;
            }

            $since = Carbon::parse($failingSince);

            if ($since->diffInMinutes(now()) < $threshold) {
                return;
            }

            Cache::put(self::OUTAGE_ALERTED_KEY, true, now()->addDays(7));

            app(SlackService::class)->notifyOps(
                'Inbound mailbox unreachable',
                "The setup mailbox has been unreachable since {$since->toDayDateTimeString()}.\n"
                ."Artists emailing their work in are getting no response, and nothing is being processed.\n"
                ."*Error:* {$error}"
            );
        } catch (\Throwable $e) {
            // The alert is a courtesy. A cache or webhook problem must not turn
            // an unreachable mailbox into a second failure on top of it.
            Log::warning('FetchInboundEmails: could not report the outage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clears the outage state, and says so if an alert went out for it.
     */
    private function reportRecovery(): void
    {
        try {
            if (! Cache::get(self::OUTAGE_SINCE_KEY)) {
                return;
            }

            $alerted = Cache::get(self::OUTAGE_ALERTED_KEY);

            Cache::forget(self::OUTAGE_SINCE_KEY);
            Cache::forget(self::OUTAGE_ALERTED_KEY);

            if ($alerted) {
                app(SlackService::class)->notifyOps(
                    'Inbound mailbox is back',
                    'The setup mailbox is reachable again and queued messages are being processed.'
                );
            }
        } catch (\Throwable $e) {
            Log::warning('FetchInboundEmails: could not clear the outage state', ['error' => $e->getMessage()]);
        }
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
