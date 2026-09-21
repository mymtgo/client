<?php

use App\Actions\Sidecar\BuildSidecarMatchView;
use App\Actions\Sidecar\IngestSidecarEvents;
use App\Facades\AppSettings;
use App\Models\GameEvent;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sidecar-view-'.uniqid();
    mkdir($this->dir);
    copy(base_path('tests/fixtures/sidecar/events-fixture.ndjson'), $this->dir.'/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson');
    copy(base_path('tests/fixtures/sidecar/status.json'), $this->dir.'/status.json');
    AppSettings::setSidecarDirectory($this->dir);
    IngestSidecarEvents::resetTick();
    IngestSidecarEvents::run();
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('returns null for an unknown match', function () {
    expect(BuildSidecarMatchView::run('000'))->toBeNull();
});

it('folds match-level facts', function () {
    $view = BuildSidecarMatchView::run('288955358');

    expect($view->playerNames)->toBe([0 => 'saidin.raken', 1 => 'Opp_Name'])
        ->and($view->matchEnded)->toBeTrue()
        ->and($view->matchResult)->toBe(['winner' => null, 'score' => [1, 1]])
        ->and($view->allVerified)->toBeTrue()
        ->and($view->latestProbeUsername)->toBe('saidin.raken')
        ->and(array_keys($view->games))->toBe([958291826, 958292028]);
});

it('folds game one with clock summary and on play', function () {
    $g = BuildSidecarMatchView::run('288955358')->games['958291826'];

    expect($g->gameNumber)->toBe(1)
        ->and($g->hasStart)->toBeTrue()
        ->and($g->hasEnd)->toBeTrue()
        ->and($g->coverageComplete())->toBeTrue()
        ->and($g->startedAt->toIso8601ZuluString('millisecond'))->toBe('2026-08-05T12:16:10.000Z')
        ->and($g->endedAt->toIso8601ZuluString('millisecond'))->toBe('2026-08-05T12:24:00.500Z')
        ->and($g->winnerName)->toBe('saidin.raken')
        ->and($g->onPlayName)->toBe('saidin.raken')
        ->and($g->turnCount)->toBe(2)
        ->and($g->clockByName)->toBe([
            'saidin.raken' => ['start' => 1500000, 'end' => 1300000, 'min' => 1300000, 'sideboard_used' => null],
            'Opp_Name' => ['start' => 1500000, 'end' => 1200000, 'min' => 1200000, 'sideboard_used' => null],
        ])
        ->and($g->keyframes)->toHaveCount(2)
        ->and($g->keyframes[1]['trigger'])->toBe('turn');
});

it('folds game two with the opponent winning on the play', function () {
    $g = BuildSidecarMatchView::run('288955358')->games['958292028'];

    expect($g->winnerName)->toBe('Opp_Name')
        ->and($g->onPlayName)->toBe('Opp_Name')
        ->and($g->clockByName['saidin.raken'])->toBe(['start' => 1500000, 'end' => 900000, 'min' => 900000, 'sideboard_used' => 65000])
        ->and($g->clockByName['Opp_Name']['sideboard_used'])->toBe(105000);
});

it('orders across sessions by session_started_at then seq', function () {
    // `seq` restarts per file, so the unique index is (log_instance_id, seq); a
    // real second session means a second ingested file, i.e. a second LogInstance.
    $secondInstance = LogInstance::factory()->create();

    GameEvent::query()->where('game_mtgo_id', '958291826')->where('seq', '>=', 14)->update([
        'log_instance_id' => $secondInstance->id,
        'session' => 'bbbbbbbb-0000-0000-0000-000000000002',
        'session_started_at' => '2026-08-05 12:16:40.500',
    ]);
    GameEvent::query()->where('game_mtgo_id', '958291826')->where('seq', '>=', 14)->decrement('seq', 13);
    // (rows now carry seq 1..8 in the second session; the first session keeps seq 4..13)

    $g = BuildSidecarMatchView::run('288955358')->games['958291826'];

    expect($g->turnCount)->toBe(2)
        ->and($g->hasEnd)->toBeTrue()
        ->and($g->winnerName)->toBe('saidin.raken');
});

it('reports incomplete coverage when game_ended is missing', function () {
    GameEvent::where('game_mtgo_id', '958292028')->where('type', 'game_ended')->delete();

    $g = BuildSidecarMatchView::run('288955358')->games['958292028'];

    expect($g->hasEnd)->toBeFalse()
        ->and($g->coverageComplete())->toBeFalse()
        ->and($g->winnerName)->toBeNull()
        ->and($g->endedAt)->toBeNull();
});

it('reports allVerified false when any event is unverified', function () {
    GameEvent::where('game_mtgo_id', '958291826')->where('seq', 9)->update(['verified' => false]);

    $view = BuildSidecarMatchView::run('288955358');

    expect($view->games['958291826']->allVerified)->toBeFalse()
        ->and($view->games['958292028']->allVerified)->toBeTrue()
        ->and($view->allVerified)->toBeFalse();
});

it('counts an unverified event the fold never reads as an unverified match and game', function () {
    // card_tapped is replay detail, outside the fold's type list, but the
    // spec's gate is "every event for the subject has verified = true", so
    // a degraded stream must still close the gate.
    GameEvent::factory()->create([
        'log_instance_id' => GameEvent::first()->log_instance_id,
        'session' => 'aaaaaaaa-0000-0000-0000-000000000001',
        'session_started_at' => '2026-08-05 11:20:47.000',
        'seq' => 500,
        'ts' => '2026-08-05 12:20:00.000',
        'type' => 'card_tapped',
        'game_mtgo_id' => '958291826',
        'match_mtgo_id' => '288955358',
        'verified' => false,
    ]);

    $view = BuildSidecarMatchView::run('288955358');

    expect($view->allVerified)->toBeFalse()
        ->and($view->games['958291826']->allVerified)->toBeFalse()
        ->and($view->games['958292028']->allVerified)->toBeTrue();
});

it('still folds a match whose unverified event is match level', function () {
    GameEvent::factory()->create([
        'log_instance_id' => GameEvent::first()->log_instance_id,
        'session' => 'aaaaaaaa-0000-0000-0000-000000000001',
        'session_started_at' => '2026-08-05 11:20:47.000',
        'seq' => 501,
        'ts' => '2026-08-05 12:20:00.000',
        'type' => 'card_tapped',
        'game_mtgo_id' => null,
        'match_mtgo_id' => '288955358',
        'verified' => false,
    ]);

    $view = BuildSidecarMatchView::run('288955358');

    expect($view->allVerified)->toBeFalse()
        ->and($view->games['958291826']->allVerified)->toBeTrue();
});
