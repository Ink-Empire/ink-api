<?php

namespace App\Exceptions;

/**
 * A calendar token refresh failed for a reason that may not last.
 *
 * Google was unreachable, rate limited, or answered with something other than
 * invalid_grant. The connection is left untouched so the caller can retry.
 */
class CalendarRefreshFailedException extends \Exception {}
