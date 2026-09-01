<?php

namespace Tests\Unit;

use App\Services\GoogleCalendarService;
use Google\Service\Exception as GoogleServiceException;
use ReflectionMethod;
use Tests\TestCase;

class AlreadyDeletedEventTest extends TestCase
{
    /**
     * The case that raised the alert. Deleting an event already removed in
     * Google answers 410, which was treated as a failure, retried three times
     * and then reported, for work that had in fact been done.
     */
    public function test_410_gone_counts_as_already_deleted(): void
    {
        $this->assertTrue($this->alreadyGone(410));
    }

    public function test_404_not_found_counts_as_already_deleted(): void
    {
        $this->assertTrue($this->alreadyGone(404));
    }

    /**
     * Anything else has to keep throwing. A revoked token or a Google outage
     * during a delete is a real failure and should retry, not be swallowed as
     * if the event had been removed.
     */
    public function test_other_failures_still_throw(): void
    {
        foreach ([401, 403, 429, 500, 503] as $code) {
            $this->assertFalse($this->alreadyGone($code), "code {$code} should not be treated as deleted");
        }
    }

    private function alreadyGone(int $code): bool
    {
        $method = new ReflectionMethod(GoogleCalendarService::class, 'alreadyGone');
        $method->setAccessible(true);

        return $method->invoke(new GoogleCalendarService, new GoogleServiceException('failed', $code));
    }
}
