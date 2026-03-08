<?php

namespace App\Util;

class ParseInput
{
    /**
     * Parse a mixed input into an array of integer IDs.
     * Handles: native array, JSON-encoded string, or comma-separated string.
     */
    public static function ids($input): array
    {
        if (empty($input)) {
            return [];
        }

        if (is_array($input)) {
            return array_map('intval', $input);
        }

        $decoded = json_decode($input, true);
        if (is_array($decoded)) {
            return array_map('intval', $decoded);
        }

        return array_map('intval', explode(',', $input));
    }
}
