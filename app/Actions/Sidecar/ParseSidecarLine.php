<?php

namespace App\Actions\Sidecar;

use App\Sidecar\SidecarEventLine;
use App\Sidecar\UnsupportedSidecarSchemaException;
use Carbon\CarbonImmutable;

class ParseSidecarLine
{
    private const REQUIRED = ['v', 'session', 'session_started_at', 'seq', 'ts', 'type', 'data'];

    /**
     * @throws \JsonException on malformed JSON
     * @throws UnsupportedSidecarSchemaException on a schema major other than 1
     * @throws \InvalidArgumentException when a required field is missing
     */
    public static function run(string $line): ?SidecarEventLine
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $json = json_decode($line, true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($json)) {
            throw new \InvalidArgumentException('Sidecar line is not a JSON object');
        }

        foreach (self::REQUIRED as $key) {
            if (! array_key_exists($key, $json)) {
                throw new \InvalidArgumentException("Sidecar line missing required field '{$key}'");
            }
        }

        if ((int) $json['v'] !== SidecarEventLine::SUPPORTED_MAJOR) {
            throw new UnsupportedSidecarSchemaException("Unsupported sidecar schema v{$json['v']}");
        }

        return new SidecarEventLine(
            v: (int) $json['v'],
            session: (string) $json['session'],
            sessionStartedAt: CarbonImmutable::parse($json['session_started_at'])->utc(),
            seq: (int) $json['seq'],
            ts: CarbonImmutable::parse($json['ts'])->utc(),
            type: (string) $json['type'],
            game: isset($json['game']) ? (string) $json['game'] : null,
            match: isset($json['match']) ? (string) $json['match'] : null,
            verified: (bool) ($json['verified'] ?? false),
            data: is_array($json['data']) ? $json['data'] : [],
            ref: isset($json['ref']) && is_array($json['ref']) ? $json['ref'] : null,
        );
    }
}
