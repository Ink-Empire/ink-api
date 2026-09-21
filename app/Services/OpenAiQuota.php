<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recognises the OpenAI failures that are about the account rather than the
 * request, and reports them once instead of once per image.
 *
 * When the credits ran out in September, a bulk upload put 91 identical errors
 * into Sentry, one for every image in the batch. They were not 91 problems.
 * They were one billing lapse, and the volume buried it.
 *
 * The reported flag is static, so it lasts for the life of the process. A
 * Horizon worker chewing through a batch reports the first failure and stays
 * quiet for the rest; a fresh worker will report once more. That is the right
 * granularity: enough to notice, not enough to drown.
 */
class OpenAiQuota
{
    private static bool $reported = false;

    /**
     * Whether this failure means the account cannot make requests at all, as
     * opposed to something wrong with the particular image.
     */
    public static function isExhausted(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (['no credits remaining', 'insufficient_quota', 'exceeded your current quota', 'billing hard limit'] as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records a quota failure, loudly the first time and quietly after that.
     *
     * $context says which caller hit it, since the account being out affects
     * tag suggestions, style suggestions and bulk uploads alike.
     */
    public static function report(Throwable $e, string $context): void
    {
        if (self::$reported) {
            Log::debug('OpenAI still out of credits', ['context' => $context]);

            return;
        }

        self::$reported = true;

        Log::error('OpenAI credits are exhausted, so AI suggestions are unavailable', [
            'context' => $context,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Lets a test start from a clean slate, since the flag outlives an
     * individual test otherwise.
     */
    public static function forget(): void
    {
        self::$reported = false;
    }
}
