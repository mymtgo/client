<?php

use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\Widgets\ArchetypeStatsWidget;
use App\Enums\MatchOutcome;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('validates the archetype id shape', function () {
    $widget = new ArchetypeStatsWidget;

    expect($widget->validateConfig(['archetype_id' => '7']))->toBe(['archetype_id' => 7])
        ->and(fn () => $widget->validateConfig([]))->toThrow(InvalidWidgetConfig::class)
        ->and(fn () => $widget->validateConfig(['archetype_id' => 0]))->toThrow(InvalidWidgetConfig::class);
});

it('rolls up every one of my decks on the archetype and ignores the rest', function () {
    $account = Account::create(['username' => 'me', 'active' => true, 'tracked' => true]);
    $archetype = Archetype::factory()->create(['name' => 'Eldrazi Tron', 'format' => 'modern']);
    $other = Archetype::factory()->create();

    $seed = function (int $archetypeId, bool $won, ?int $leagueId = null) use ($account) {
        $deck = Deck::factory()->create(['account_id' => $account->id, 'archetype_id' => $archetypeId]);
        $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
        $match = MtgoMatch::factory()->create([
            'deck_version_id' => $version->id,
            'outcome' => $won ? MatchOutcome::Win : MatchOutcome::Loss,
            'league_id' => $leagueId,
            'started_at' => now()->subHour(),
        ]);
        Game::factory()->count(2)->create(['match_id' => $match->id, 'won' => $won]);

        return $version;
    };

    $league = League::factory()->complete()->create(['started_at' => now()->subDay()]);
    $seed($archetype->id, true, $league->id);
    $seed($archetype->id, false);
    $seed($other->id, true);

    $data = (new ArchetypeStatsWidget)->resolve(['archetype_id' => $archetype->id], DashboardScope::fromTimeframe('alltime'));

    expect($data['archetype']['name'])->toBe('Eldrazi Tron')
        ->and($data['archetype']['format'])->toBe('Modern')
        ->and($data['deckCount'])->toBe(2)
        ->and($data['matchRecord']->wins)->toBe(1)
        ->and($data['matchRecord']->losses)->toBe(1)
        ->and($data['gamesWon'])->toBe(2)
        ->and($data['gamesLost'])->toBe(2)
        ->and($data['latestLeague']['id'])->toBe($league->id);
});

it('returns null when the archetype is gone', function () {
    expect((new ArchetypeStatsWidget)->resolve(['archetype_id' => 999], DashboardScope::fromTimeframe('alltime')))->toBeNull();
});
