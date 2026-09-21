<?php

use App\Actions\Sidecar\IngestSidecarEvents;
use App\Actions\Sidecar\ReadSidecarStatus;
use App\Facades\AppSettings;
use App\Models\GameEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sidecar-test-'.uniqid();
    mkdir($this->dir, 0777, true);
    AppSettings::setSidecarDirectory($this->dir);
    IngestSidecarEvents::resetTick();
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
});

function writeSidecarFixture(string $dir, string $name = 'events-aaaaaaaa-0000-0000-0000-000000000001.ndjson', ?int $lines = null): string
{
    $src = file(base_path('tests/fixtures/sidecar/events-fixture.ndjson'));
    $body = implode('', $lines === null ? $src : array_slice($src, 0, $lines));
    file_put_contents($dir.'/'.$name, $body);
    file_put_contents($dir.'/status.json', json_encode(['state' => 'attached', 'current_file' => $name, 'heartbeat' => now()->toIso8601ZuluString()]));

    return $dir.'/'.$name;
}

it('does nothing when the sidecar directory does not exist', function () {
    AppSettings::setSidecarDirectory(sys_get_temp_dir().'/does-not-exist-'.uniqid());

    expect(IngestSidecarEvents::run())->toBe(0)
        ->and(GameEvent::count())->toBe(0)
        ->and(LogInstance::count())->toBe(0);
});

it('ingests every line of the current file into game_events', function () {
    writeSidecarFixture($this->dir);

    $inserted = IngestSidecarEvents::run();

    expect($inserted)->toBe(30)
        ->and(GameEvent::count())->toBe(30)
        ->and(GameEvent::where('type', 'card_tapped')->value('game_mtgo_id'))->toBe('958291826')
        ->and(LogInstance::count())->toBe(1);
});

it('is idempotent across ticks', function () {
    writeSidecarFixture($this->dir);

    IngestSidecarEvents::run();
    $second = IngestSidecarEvents::run();

    expect($second)->toBe(0)->and(GameEvent::count())->toBe(30);
});

it('defers a partial trailing line and ingests it once complete', function () {
    $path = writeSidecarFixture($this->dir, lines: 3);
    $fourth = file(base_path('tests/fixtures/sidecar/events-fixture.ndjson'))[3];
    file_put_contents($path, substr($fourth, 0, 40), FILE_APPEND);

    IngestSidecarEvents::run();
    expect(GameEvent::count())->toBe(3);

    file_put_contents($path, substr($fourth, 40), FILE_APPEND);

    IngestSidecarEvents::run();
    expect(GameEvent::count())->toBe(4)
        ->and(GameEvent::where('seq', 4)->value('type'))->toBe('game_started');
});

it('seals the instance on an unsupported schema and stops reading it', function () {
    $path = writeSidecarFixture($this->dir, lines: 2);
    file_put_contents($path, '{"v":9,"session":"s","session_started_at":"2026-08-05T11:20:47.000Z","seq":3,"ts":"2026-08-05T11:20:47.000Z","type":"x","game":null,"match":null,"verified":true,"data":{},"ref":null}'."\n", FILE_APPEND);

    IngestSidecarEvents::run();

    expect(GameEvent::count())->toBe(2)
        ->and(LogInstance::first()->sealed_at)->not->toBeNull()
        ->and(LogInstance::first()->seal_reason)->toBe('unsupported_schema');

    IngestSidecarEvents::run();
    expect(LogInstance::count())->toBe(1);
});

it('skips a malformed line and keeps going', function () {
    $path = writeSidecarFixture($this->dir, lines: 2);
    file_put_contents($path, "{not json}\n", FILE_APPEND);
    file_put_contents($path, file(base_path('tests/fixtures/sidecar/events-fixture.ndjson'))[3], FILE_APPEND);

    IngestSidecarEvents::run();

    expect(GameEvent::count())->toBe(3)
        ->and(GameEvent::orderByDesc('seq')->value('seq'))->toBe(4);
});

it('tails only the current file on ordinary ticks and picks up other files on a rescan tick', function () {
    writeSidecarFixture($this->dir);
    file_put_contents($this->dir.'/events-bbbbbbbb-0000-0000-0000-000000000002.ndjson', file(base_path('tests/fixtures/sidecar/events-fixture.ndjson'))[0]);

    IngestSidecarEvents::run();
    expect(GameEvent::count())->toBe(31)->and(LogInstance::count())->toBe(2);
    file_put_contents($this->dir.'/events-cccccccc-0000-0000-0000-000000000003.ndjson', file(base_path('tests/fixtures/sidecar/events-fixture.ndjson'))[1]);

    IngestSidecarEvents::run();
    expect(LogInstance::count())->toBe(2);

    for ($i = 0; $i < 29; $i++) {
        IngestSidecarEvents::run();
    }
    expect(LogInstance::count())->toBe(3)->and(GameEvent::count())->toBe(32);
});

it('seals a fully read file that is no longer current', function () {
    writeSidecarFixture($this->dir, name: 'events-aaaaaaaa-0000-0000-0000-000000000001.ndjson');
    IngestSidecarEvents::run();

    writeSidecarFixture($this->dir, name: 'events-bbbbbbbb-0000-0000-0000-000000000002.ndjson', lines: 1);
    IngestSidecarEvents::resetTick();
    IngestSidecarEvents::run();

    $old = LogInstance::where('file_path', 'like', '%aaaaaaaa%')->first();
    expect($old->sealed_at)->not->toBeNull()->and($old->seal_reason)->toBe('session_rotated');
});

it('does not seal a caught-up file as session_rotated when status.json is missing', function () {
    $path = $this->dir.'/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson';
    $src = file(base_path('tests/fixtures/sidecar/events-fixture.ndjson'));
    file_put_contents($path, implode('', array_slice($src, 0, 3)));

    IngestSidecarEvents::run();

    expect(GameEvent::count())->toBe(3)
        ->and(LogInstance::first()->sealed_at)->toBeNull();

    file_put_contents($this->dir.'/status.json', json_encode([
        'state' => 'attached',
        'current_file' => basename($path),
        'heartbeat' => now()->toIso8601ZuluString(),
    ]));
    file_put_contents($path, $src[3], FILE_APPEND);

    IngestSidecarEvents::run();

    expect(GameEvent::count())->toBe(4)
        ->and(LogInstance::first()->sealed_at)->toBeNull();
});

it('ReadSidecarStatus returns null on a malformed heartbeat instead of throwing', function () {
    file_put_contents($this->dir.'/status.json', json_encode([
        'state' => 'attached',
        'current_file' => 'x',
        'heartbeat' => 'not-a-date',
    ]));

    expect(ReadSidecarStatus::run())->toBeNull();
});

it('tolerates a malformed status.json during a full tick instead of aborting it', function () {
    $path = writeSidecarFixture($this->dir, lines: 3);
    file_put_contents($this->dir.'/status.json', json_encode([
        'state' => 'attached',
        'current_file' => basename($path),
        'heartbeat' => 'not-a-date',
    ]));

    $inserted = IngestSidecarEvents::run();

    expect($inserted)->toBe(3)->and(GameEvent::count())->toBe(3);
});
