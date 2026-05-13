<?php

namespace App\Actions\Logs;

class ExtractMetaMessageBytes
{
    /**
     * Extract the MetaMessage byte array from a raw log line.
     * Returns null when the line has no MetaMessage.
     *
     * @return array<int, int>|null
     */
    public static function run(string $rawText): ?array
    {
        if (! preg_match('/"MetaMessage":\[(?<bytes>[^\]]+)\]/', $rawText, $m)) {
            return null;
        }

        return array_map('intval', explode(',', $m['bytes']));
    }
}
