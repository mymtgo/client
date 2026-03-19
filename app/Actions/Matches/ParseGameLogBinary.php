<?php

namespace App\Actions\Matches;

use Carbon\Carbon;

class ParseGameLogBinary
{
    /**
     * .NET epoch offset: ticks between 0001-01-01 and 1970-01-01.
     */
    private const DOTNET_EPOCH_OFFSET = 621355968000000000;

    /**
     * Current parser version. Increment when parsing logic changes
     * to trigger re-parsing of previously decoded entries.
     */
    public const VERSION = 1;

    /**
     * Parse a binary GameLog .dat file into structured entries.
     *
     * @param  string  $raw  Raw file contents
     * @param  int|null  $byteOffset  Resume parsing from this byte position (skip header)
     * @return array{match_uuid: ?string, game_uuid: ?string, version: ?int, type: ?int, entries: array, byte_offset: int}|null
     */
    public static function run(string $raw, ?int $byteOffset = null): ?array
    {
        $length = strlen($raw);

        if ($length < 78) {
            return null;
        }

        $pos = 0;

        // Parse header (only on full parse, not incremental)
        if ($byteOffset === null) {
            $version = ord($raw[$pos++]);
            $pos++; // unknown flag

            $uuidLen = ord($raw[$pos++]);
            if ($pos + $uuidLen > $length) {
                return null;
            }
            $matchUuid = substr($raw, $pos, $uuidLen);
            $pos += $uuidLen;

            $type = ord($raw[$pos++]);
            $pos++; // unknown flag

            $uuidLen2 = ord($raw[$pos++]);
            if ($pos + $uuidLen2 > $length) {
                return null;
            }
            $gameUuid = substr($raw, $pos, $uuidLen2);
            $pos += $uuidLen2;
        } else {
            // Incremental: skip header, start at offset
            $pos = $byteOffset;
            $matchUuid = null;
            $gameUuid = null;
            $version = null;
            $type = null;
        }

        $entries = [];

        while ($pos + 10 <= $length) {
            $entryStart = $pos;

            // 8-byte timestamp (int64 little-endian, .NET DateTime ticks)
            // Using 'P' (unsigned 64-bit LE) — valid ticks are always positive
            $ticks = unpack('P', substr($raw, $pos, 8))[1];
            $pos += 8;

            // 1-byte flag
            $pos++;

            // Varint message length (.NET Write7BitEncodedInt)
            $msgLen = 0;
            $shift = 0;
            do {
                if ($pos >= $length) {
                    $pos = $entryStart;
                    break 2;
                }
                $byte = ord($raw[$pos++]);
                $msgLen |= ($byte & 0x7F) << $shift;
                $shift += 7;
            } while ($byte & 0x80);

            // Read message
            if ($pos + $msgLen > $length) {
                $pos = $entryStart;
                break;
            }

            $message = substr($raw, $pos, $msgLen);
            $pos += $msgLen;

            // Convert .NET ticks to ISO 8601 with milliseconds
            $unixSeconds = ($ticks - self::DOTNET_EPOCH_OFFSET) / 10_000_000;
            $timestamp = Carbon::createFromTimestamp($unixSeconds, 'UTC')->toIso8601String();

            $entries[] = [
                'timestamp' => $timestamp,
                'message' => $message,
            ];
        }

        return [
            'match_uuid' => $matchUuid,
            'game_uuid' => $gameUuid,
            'version' => $version,
            'type' => $type,
            'entries' => $entries,
            'byte_offset' => $pos,
        ];
    }
}
