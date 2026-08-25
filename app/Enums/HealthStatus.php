<?php

namespace App\Enums;

class HealthStatus
{
    const OK = 'ok';
    const WARN = 'warn';
    const CRITICAL = 'critical';

    /**
     * Worst status in a set, so a single failure decides the overall result.
     */
    public static function worst(array $statuses): string
    {
        if (in_array(self::CRITICAL, $statuses, true)) {
            return self::CRITICAL;
        }

        if (in_array(self::WARN, $statuses, true)) {
            return self::WARN;
        }

        return self::OK;
    }

    public static function isFailing(string $status): bool
    {
        return $status !== self::OK;
    }
}
