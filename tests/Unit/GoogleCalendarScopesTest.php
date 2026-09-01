<?php

namespace Tests\Unit;

use App\Services\GoogleCalendarService;
use Tests\TestCase;

class GoogleCalendarScopesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => '123456789-abc.apps.googleusercontent.com',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => 'https://api.getinked.in/api/calendar/callback',
        ]);
    }

    /**
     * Every scope requested has to be justified to Google during OAuth
     * verification, and a wider scope than the code uses is grounds for
     * rejection on data minimisation. These assertions exist so widening the
     * set is a deliberate act rather than a passing convenience.
     */
    public function test_it_requests_only_the_scopes_the_code_uses(): void
    {
        $url = urldecode((new GoogleCalendarService)->getAuthUrl());

        $this->assertStringContainsString('https://www.googleapis.com/auth/calendar.events', $url);
        $this->assertStringContainsString('https://www.googleapis.com/auth/userinfo.email', $url);
        $this->assertStringContainsString('https://www.googleapis.com/auth/userinfo.profile', $url);
    }

    /**
     * calendar.readonly grants every calendar the artist can see. Nothing here
     * reads a calendar other than the one being synced, and dropping it was
     * what removed the second sensitive scope from the consent screen.
     */
    public function test_it_does_not_request_calendar_readonly(): void
    {
        $url = urldecode((new GoogleCalendarService)->getAuthUrl());

        $this->assertStringNotContainsString('auth/calendar.readonly', $url);
    }

    public function test_it_requests_no_other_calendar_scopes(): void
    {
        $url = urldecode((new GoogleCalendarService)->getAuthUrl());

        foreach ([
            'auth/calendar.acls',
            'auth/calendar.app.created',
            'auth/calendar.calendarlist',
            'auth/calendar.calendars',
            'auth/calendar.events.freebusy',
            'auth/calendar.settings',
        ] as $scope) {
            $this->assertStringNotContainsString($scope, $url);
        }
    }
}
