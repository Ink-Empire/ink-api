<?php

namespace App\Exceptions;

/**
 * A calendar connection can no longer refresh its token.
 *
 * Retrying cannot fix this. The owner has to reconnect the calendar, so callers
 * should stop rather than treat it as a transient failure.
 */
class CalendarReauthRequiredException extends \Exception
{

}
