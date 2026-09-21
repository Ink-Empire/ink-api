<?php

namespace Tests\Unit;

use App\Services\OpenAiQuota;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * When the credits ran out in September, a bulk upload put 91 identical
 * errors into Sentry, one per image. They were one billing lapse, and the
 * volume buried it.
 */
class OpenAiQuotaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The flag is static, so it outlives a single test.
        OpenAiQuota::forget();
    }

    protected function tearDown(): void
    {
        OpenAiQuota::forget();

        parent::tearDown();
    }

    public function test_it_recognises_an_exhausted_account(): void
    {
        $e = new RuntimeException(
            'You have no credits remaining. Add credits to continue using the API at https://platform.openai.com/settings/organization/billing/.'
        );

        $this->assertTrue(OpenAiQuota::isExhausted($e));
    }

    public function test_it_recognises_the_quota_wording_too(): void
    {
        $this->assertTrue(OpenAiQuota::isExhausted(
            new RuntimeException('You exceeded your current quota, please check your plan and billing details.')
        ));

        $this->assertTrue(OpenAiQuota::isExhausted(
            new RuntimeException('Error: insufficient_quota')
        ));
    }

    /**
     * A broken image is a real per-image problem and must keep its own error,
     * or this would hide genuine failures behind the billing case.
     */
    public function test_it_leaves_ordinary_failures_alone(): void
    {
        $this->assertFalse(OpenAiQuota::isExhausted(
            new RuntimeException('Invalid image format: unsupported mime type')
        ));

        $this->assertFalse(OpenAiQuota::isExhausted(
            new RuntimeException('Connection timed out')
        ));
    }

    public function test_it_reports_the_first_failure_loudly(): void
    {
        Log::shouldReceive('error')->once();
        Log::shouldReceive('debug')->never();

        OpenAiQuota::report(new RuntimeException('no credits remaining'), 'tag suggestions');
    }

    /**
     * The whole point: a batch of 91 images produces one error, not 91.
     */
    public function test_it_stays_quiet_for_the_rest_of_the_batch(): void
    {
        Log::shouldReceive('error')->once();
        Log::shouldReceive('debug')->times(90);

        OpenAiQuota::report(new RuntimeException('no credits remaining'), 'tag suggestions');

        for ($i = 0; $i < 90; $i++) {
            OpenAiQuota::report(new RuntimeException('no credits remaining'), 'tag suggestions');
        }
    }

    /**
     * Tag and style suggestions fail together when the account is out, and it
     * is still one problem across both.
     */
    public function test_it_does_not_repeat_itself_across_callers(): void
    {
        Log::shouldReceive('error')->once();
        Log::shouldReceive('debug')->once();

        OpenAiQuota::report(new RuntimeException('no credits remaining'), 'tag suggestions');
        OpenAiQuota::report(new RuntimeException('no credits remaining'), 'style suggestions');
    }
}
