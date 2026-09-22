<?php

use App\Actions\Sidecar\ApplySidecarProjection;
use App\Actions\Sidecar\BuildSidecarAgreementSummary;
use App\Actions\Sidecar\IngestSidecarEvents;
use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns zeros for every field with no data', function () {
    $summary = BuildSidecarAgreementSummary::run();

    expect(array_keys($summary))->toBe(['username', 'on_play', 'game_boundaries', 'game_result', 'match_result'])
        ->and($summary['game_result'])->toBe(['agree' => 0, 'disagree' => 0, 'sidecar_incomplete' => 0, 'sidecar_degraded' => 0]);
});

it('counts agreement, disagreement, incomplete and degraded', function () {
    $dir = sys_get_temp_dir().'/sidecar-sum-'.uniqid();
    mkdir($dir);
    copy(base_path('tests/fixtures/sidecar/events-fixture.ndjson'), $dir.'/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson');
    copy(base_path('tests/fixtures/sidecar/status.json'), $dir.'/status.json');
    AppSettings::setSidecarDirectory($dir);
    AppSettings::setSystemTimezone('Europe/London');
    IngestSidecarEvents::resetTick();
    IngestSidecarEvents::run();

    $match = MtgoMatch::factory()->create(['mtgo_id' => '288955358']);
    $local = Player::factory()->create(['username' => 'local.player']);
    $opp = Player::factory()->create(['username' => 'Opp_Name']);
    $g1 = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => '958291826', 'won' => true]);
    $g2 = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => '958292028', 'won' => true]);
    foreach ([$g1, $g2] as $g) {
        $g->players()->attach($local->id, ['instance_id' => 0, 'is_local' => true, 'on_play' => true]);
        $g->players()->attach($opp->id, ['instance_id' => 1, 'is_local' => false, 'on_play' => false]);
    }
    GameTimeline::create(['game_id' => $g1->id, 'timestamp' => '13:16:41', 'content' => json_encode([
        'Players' => [['Id' => 0, 'Name' => 'local.player'], ['Id' => 1, 'Name' => 'Opp_Name']],
        'Cards' => [['Id' => 445, 'CatalogID' => 132587, 'Zone' => 'Battlefield', 'Owner' => 0], ['Id' => 446, 'CatalogID' => 39339, 'Zone' => 'Battlefield', 'Owner' => 0]],
    ])]);

    GameEvent::where('game_mtgo_id', '958292028')->where('type', 'game_ended')->delete();

    ApplySidecarProjection::run($match);

    $summary = BuildSidecarAgreementSummary::run();

    expect($summary['game_result']['agree'])->toBe(1)
        ->and($summary['game_result']['disagree'])->toBe(0)
        ->and($summary['game_result']['sidecar_incomplete'])->toBe(1)
        ->and($summary['on_play']['disagree'])->toBe(1);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('treats a complete but unverified game as degraded, not agree', function () {
    $match = MtgoMatch::factory()->create(['mtgo_id' => '111']);
    Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => '222']);

    GameEvent::factory()->create(['type' => 'game_started', 'game_mtgo_id' => '222', 'match_mtgo_id' => '111', 'verified' => true]);
    GameEvent::factory()->create(['type' => 'game_ended', 'game_mtgo_id' => '222', 'match_mtgo_id' => '111', 'verified' => false]);

    $summary = BuildSidecarAgreementSummary::run();

    expect($summary['game_result'])->toBe(['agree' => 0, 'disagree' => 0, 'sidecar_incomplete' => 0, 'sidecar_degraded' => 1]);
});

it('computes match_result completeness at the match level, not from game totals', function () {
    $match = MtgoMatch::factory()->create(['mtgo_id' => '333']);
    Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => '444']);
    Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => '555']);

    foreach (['444', '555'] as $gameMtgoId) {
        GameEvent::factory()->create(['type' => 'game_started', 'game_mtgo_id' => $gameMtgoId, 'match_mtgo_id' => '333', 'verified' => true]);
        GameEvent::factory()->create(['type' => 'game_ended', 'game_mtgo_id' => $gameMtgoId, 'match_mtgo_id' => '333', 'verified' => true]);
    }

    // Both games are individually complete and verified, but the match
    // itself never ended: a broken implementation that reuses the
    // per-game incomplete/degraded totals for match_result would show 0
    // here instead of 1.
    $summary = BuildSidecarAgreementSummary::run();

    expect($summary['game_result']['sidecar_incomplete'])->toBe(0)
        ->and($summary['game_result']['sidecar_degraded'])->toBe(0)
        ->and($summary['match_result'])->toBe(['agree' => 0, 'disagree' => 0, 'sidecar_incomplete' => 1, 'sidecar_degraded' => 0]);
});
