<?php

use App\Actions\Sidecar\ReadMatchSnapshot;
use App\Facades\AppSettings;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\SidecarSnapshotFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $dir = sys_get_temp_dir().'/sidecar-read-'.uniqid();
    mkdir($dir);
    AppSettings::setSidecarDirectory($dir);
});

it('reads nothing when the sidecar directory is absent', function () {
    $match = MtgoMatch::factory()->create();
    SidecarSnapshotFactory::snapshot($match, 'started', SidecarSnapshotFactory::league());
    AppSettings::setSidecarDirectory(sys_get_temp_dir().'/nope-'.uniqid());

    expect(ReadMatchSnapshot::run($match, ReadMatchSnapshot::PHASE_STARTED))->toBeNull();
});

it('reads the verified snapshot for the phase', function () {
    $match = MtgoMatch::factory()->create();
    SidecarSnapshotFactory::snapshot($match, 'started', SidecarSnapshotFactory::league(), SidecarSnapshotFactory::deck());
    SidecarSnapshotFactory::snapshot($match, 'ended', SidecarSnapshotFactory::league(['match_number' => 2]));

    $started = ReadMatchSnapshot::run($match, ReadMatchSnapshot::PHASE_STARTED);
    $ended = ReadMatchSnapshot::run($match, ReadMatchSnapshot::PHASE_ENDED);

    expect($started->league->matchNumber)->toBe(1)
        ->and($started->registeredDeck->netDeckId)->toBe(12345)
        ->and($ended->league->matchNumber)->toBe(2);
});

it('ignores unverified snapshots', function () {
    $match = MtgoMatch::factory()->create();
    SidecarSnapshotFactory::snapshot($match, 'started', SidecarSnapshotFactory::league(), verified: false);

    expect(ReadMatchSnapshot::run($match, ReadMatchSnapshot::PHASE_STARTED))->toBeNull();
});

it('returns null when the match has no snapshot', function () {
    expect(ReadMatchSnapshot::run(MtgoMatch::factory()->create(), ReadMatchSnapshot::PHASE_STARTED))->toBeNull();
});

it('reads a non-league snapshot with no league', function () {
    $match = MtgoMatch::factory()->create();
    SidecarSnapshotFactory::snapshot($match, 'started', deck: SidecarSnapshotFactory::deck());

    $snapshot = ReadMatchSnapshot::run($match, ReadMatchSnapshot::PHASE_STARTED);

    expect($snapshot->league)->toBeNull()->and($snapshot->registeredDeck)->not->toBeNull();
});
