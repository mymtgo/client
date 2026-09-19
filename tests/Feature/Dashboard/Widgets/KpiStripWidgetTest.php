<?php

use App\Dashboard\DashboardScope;
use App\Dashboard\Widgets\KpiStripWidget;
use App\Enums\MatchOutcome;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the match record, game winrate and deltas in one payload', function () {
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    foreach ([MatchOutcome::Win, MatchOutcome::Loss, MatchOutcome::Draw] as $outcome) {
        $match = MtgoMatch::factory()->create([
            'deck_version_id' => $version->id,
            'outcome' => $outcome,
            'started_at' => now()->subHour(),
        ]);
        Game::factory()->create(['match_id' => $match->id, 'won' => $outcome === MatchOutcome::Win]);
    }

    $data = (new KpiStripWidget)->resolve([], DashboardScope::fromTimeframe('alltime'));

    expect($data['matchRecord']->wins)->toBe(1)
        ->and($data['matchRecord']->losses)->toBe(1)
        ->and($data['matchRecord']->draws)->toBe(1)
        ->and($data['gamesWon'])->toBe(1)
        ->and($data['gamesLost'])->toBe(2)
        ->and($data)->toHaveKeys(['streak', 'matchWinrateDelta', 'gameWinrateDelta', 'playDrawSplit', 'activeLeague']);
});
