<?php

use App\Actions\Sidecar\CrossCheckSidecarGame;
use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarGameView;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function keyframeView(array $cards, string $ts = '2026-08-05T12:16:41.050Z'): SidecarGameView
{
    return new SidecarGameView(
        gameMtgoId: '958291826',
        gameNumber: 1,
        playerNames: [0 => 'local.player', 1 => 'Opp_Name'],
        startedAt: CarbonImmutable::parse('2026-08-05T12:16:10Z'),
        endedAt: null,
        winnerName: null,
        onPlayName: null,
        hasStart: true,
        hasEnd: false,
        allVerified: true,
        turnCount: 2,
        clockByName: [],
        keyframes: [[
            'trigger' => 'turn', 'turn' => 2, 'ts' => $ts,
            'players' => [['p' => 0], ['p' => 1]],
            'cards' => $cards,
        ]],
    );
}

function snapshot(Game $game, array $cards, string $timestamp = '13:16:41'): void
{
    GameTimeline::create([
        'game_id' => $game->id,
        'timestamp' => $timestamp,
        'content' => [
            'Players' => [['Id' => 0, 'Name' => 'local.player'], ['Id' => 1, 'Name' => 'Opp_Name']],
            'Cards' => $cards,
        ],
    ]);
}

beforeEach(function () {
    AppSettings::setSystemTimezone('Europe/London');
    $match = MtgoMatch::factory()->create(['mtgo_id' => '288955358']);
    $this->game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => '958291826']);
});

it('passes when the battlefield multisets agree', function () {
    snapshot($this->game, [
        ['Id' => 445, 'CatalogID' => 132587, 'Zone' => 'Battlefield', 'Owner' => 0],
        ['Id' => 446, 'CatalogID' => 39339, 'Zone' => 'Battlefield', 'Owner' => 0],
        ['Id' => 900, 'CatalogID' => 1, 'Zone' => 'Library', 'Owner' => 1],
    ]);

    $result = CrossCheckSidecarGame::run($this->game, keyframeView([
        ['c' => '445', 'zone' => 'Battlefield', 'owner_p' => 0, 'catalog_id' => 132587],
        ['c' => '446', 'zone' => 'Battlefield', 'owner_p' => 0, 'catalog_id' => 39339],
    ]));

    expect($result->passed)->toBeTrue()->and($result->compared)->toBe(1)->and($result->failures)->toBe([]);
});

it('fails when the sidecar sees a card the snapshot does not', function () {
    snapshot($this->game, [
        ['Id' => 445, 'CatalogID' => 132587, 'Zone' => 'Battlefield', 'Owner' => 0],
    ]);

    $result = CrossCheckSidecarGame::run($this->game, keyframeView([
        ['c' => '445', 'zone' => 'Battlefield', 'owner_p' => 0, 'catalog_id' => 132587],
        ['c' => '446', 'zone' => 'Battlefield', 'owner_p' => 0, 'catalog_id' => 39339],
    ]));

    expect($result->passed)->toBeFalse()->and($result->failures[0])->toContain('local.player');
});

it('ignores hidden zones', function () {
    snapshot($this->game, [
        ['Id' => 445, 'CatalogID' => 132587, 'Zone' => 'Battlefield', 'Owner' => 0],
        ['Id' => 901, 'CatalogID' => 5, 'Zone' => 'Hand', 'Owner' => 0],
    ]);

    $result = CrossCheckSidecarGame::run($this->game, keyframeView([
        ['c' => '445', 'zone' => 'Battlefield', 'owner_p' => 0, 'catalog_id' => 132587],
    ]));

    expect($result->passed)->toBeTrue();
});

it('skips keyframes with no snapshot within sixty seconds', function () {
    snapshot($this->game, [['Id' => 1, 'CatalogID' => 1, 'Zone' => 'Battlefield', 'Owner' => 0]], timestamp: '09:00:00');

    $result = CrossCheckSidecarGame::run($this->game, keyframeView([
        ['c' => '445', 'zone' => 'Battlefield', 'owner_p' => 0, 'catalog_id' => 132587],
    ]));

    expect($result->passed)->toBeTrue()->and($result->compared)->toBe(0);
});

it('skips keyframes with an empty card list', function () {
    snapshot($this->game, [['Id' => 1, 'CatalogID' => 1, 'Zone' => 'Battlefield', 'Owner' => 0]]);

    $result = CrossCheckSidecarGame::run($this->game, keyframeView([]));

    expect($result->compared)->toBe(0);
});
