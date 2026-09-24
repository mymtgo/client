<?php

use App\Actions\Sidecar\ParseSidecarLine;
use App\Sidecar\SidecarEventLine;
use App\Sidecar\UnsupportedSidecarSchemaException;

it('parses a valid line into a value object', function () {
    $line = file(base_path('tests/fixtures/sidecar/events-fixture.ndjson'))[8];

    $parsed = ParseSidecarLine::run($line);

    expect($parsed)->toBeInstanceOf(SidecarEventLine::class)
        ->and($parsed->seq)->toBe(9)
        ->and($parsed->type)->toBe('card_tapped')
        ->and($parsed->game)->toBe('958291826')
        ->and($parsed->match)->toBe('288955358')
        ->and($parsed->verified)->toBeTrue()
        ->and($parsed->data)->toBe(['c' => '445'])
        ->and($parsed->ts->toIso8601ZuluString('millisecond'))->toBe('2026-08-05T12:16:16.000Z')
        ->and($parsed->sessionStartedAt->toIso8601ZuluString('millisecond'))->toBe('2026-08-05T11:20:47.000Z');
});

it('returns null for a blank line', function () {
    expect(ParseSidecarLine::run("\n"))->toBeNull()
        ->and(ParseSidecarLine::run(''))->toBeNull();
});

it('throws for an unsupported schema major', function () {
    $line = '{"v":2,"session":"s","session_started_at":"2026-08-05T11:20:47.000Z","seq":1,"ts":"2026-08-05T11:20:47.000Z","type":"x","game":null,"match":null,"verified":true,"data":{},"ref":null}';

    expect(fn () => ParseSidecarLine::run($line))->toThrow(UnsupportedSidecarSchemaException::class);
});

it('throws JsonException for malformed json', function () {
    expect(fn () => ParseSidecarLine::run('{"v":1,"seq":'))->toThrow(JsonException::class);
});

it('throws when a required field is missing', function () {
    $line = '{"v":1,"seq":1,"type":"x","data":{}}';

    expect(fn () => ParseSidecarLine::run($line))->toThrow(InvalidArgumentException::class);
});

it('converts to an insert row', function () {
    $line = file(base_path('tests/fixtures/sidecar/events-fixture.ndjson'))[8];
    $row = ParseSidecarLine::run($line)->toRow(logInstanceId: 12);

    expect($row)->toMatchArray([
        'log_instance_id' => 12,
        'session' => 'aaaaaaaa-0000-0000-0000-000000000001',
        'seq' => 9,
        'source' => 'mtgo_sidecar',
        'type' => 'card_tapped',
        'game_mtgo_id' => '958291826',
        'match_mtgo_id' => '288955358',
        'verified' => true,
    ])->and($row['data'])->toBe('{"c":"445"}')
        ->and($row['ref'])->toBe('{"thing":445}')
        ->and($row['session_started_at'])->toBe('2026-08-05 11:20:47.000')
        ->and($row['ts'])->toBe('2026-08-05 12:16:16.000');
});
