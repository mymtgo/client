<?php

use App\Actions\Leagues\BuildOverlayState;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Enums\LogEventType;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function overlayDeckVersion(string $name = 'Mono Green Tron', ?string $colors = 'G'): DeckVersion
{
    $card = Card::factory()->create(['art_crop' => 'https://cards.example/karn.jpg', 'local_art_crop' => null]);
    $deck = Deck::factory()->create(['name' => $name, 'color_identity' => $colors, 'cover_id' => $card->id]);

    return DeckVersion::factory()->create(['deck_id' => $deck->id]);
}

/** @param list<'win'|'loss'> $results */
function leagueWithResults(array $results, array $attrs = [], ?DeckVersion $version = null): League
{
    $version ??= overlayDeckVersion();
    $league = League::factory()->create(array_merge(['name' => 'Modern League', 'format' => 'CModern', 'deck_version_id' => $version->id], $attrs));

    foreach ($results as $i => $result) {
        MtgoMatch::factory()->create([
            'league_id' => $league->id,
            'deck_version_id' => $version->id,
            'state' => MatchState::Complete,
            'outcome' => $result === 'win' ? MatchOutcome::Win : MatchOutcome::Loss,
            'started_at' => now()->subHours(10 - $i),
        ]);
    }

    return $league;
}

function activeMatch(League $league): MtgoMatch
{
    return MtgoMatch::factory()->inProgress()->create([
        'league_id' => $league->id,
        'deck_version_id' => $league->deck_version_id,
        'started_at' => now()->subMinutes(10),
    ]);
}

it('is idle with no league, showing the last played deck art', function () {
    $version = overlayDeckVersion();
    MtgoMatch::factory()->create(['deck_version_id' => $version->id]);

    $state = BuildOverlayState::run();

    expect($state['status'])->toBe('idle')
        ->and($state['event'])->toBeNull()
        ->and($state['record'])->toBeNull()
        ->and($state['art'])->toBe(['url' => 'https://cards.example/karn.jpg'])
        ->and($state['size'])->toBe('full');
});

it('is waiting in an active league with no active match', function () {
    leagueWithResults(['win', 'win', 'loss', 'win']);

    $state = BuildOverlayState::run();

    expect($state['status'])->toBe('waiting')
        ->and($state['event'])->toBe(['kind' => 'league', 'name' => 'Modern League', 'format' => 'Modern'])
        ->and($state['record'])->toBe(['wins' => 3, 'losses' => 1])
        ->and($state['progress'])->toBe(['played' => 4, 'of' => 5])
        ->and($state['match'])->toBeNull()
        ->and($state['deck'])->toMatchArray(['name' => 'Mono Green Tron', 'colorIdentity' => ['G']]);
});

it('is in game with the match number and real games only', function () {
    $league = leagueWithResults(['win', 'win', 'loss', 'win']);
    $match = activeMatch($league);
    Game::factory()->create(['match_id' => $match->id, 'won' => true, 'started_at' => now()->subMinutes(9)]);
    Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()->subMinutes(2)]);

    $state = BuildOverlayState::run();

    expect($state['status'])->toBe('in_game')
        ->and($state['match'])->toBe([
            'number' => 5,
            'games' => [['won' => true], ['won' => null]],
            'gamesWon' => 1,
            'gamesLost' => 0,
        ]);
});

it('is sideboarding via the log floor', function () {
    $league = leagueWithResults(['win']);
    $match = activeMatch($league);
    Game::factory()->create(['match_id' => $match->id, 'won' => true, 'started_at' => now()->subMinutes(9), 'ended_at' => now()->subMinutes(3)]);
    LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => 'mtgo.log', 'byte_offset_start' => 0, 'byte_offset_end' => 1,
        'timestamp' => now()->subMinutes(2)->format('H:i:s'), 'logged_at' => now()->subMinutes(2),
        'level' => 'INF', 'category' => 'Game Management',
        'context' => 'Match State Changed from LeagueMatchJoinedGameStartedState to LeagueMatchJoinedSideboardingState',
        'raw_text' => 'x', 'ingested_at' => now(),
        'match_token' => $match->token, 'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
    ]);

    expect(BuildOverlayState::run()['status'])->toBe('sideboarding');
});

it('counts a null-won game towards neither side', function () {
    $league = leagueWithResults([]);
    $match = activeMatch($league);
    Game::factory()->create(['match_id' => $match->id, 'won' => true, 'started_at' => now()->subMinutes(30)]);
    Game::factory()->create(['match_id' => $match->id, 'won' => null, 'started_at' => now()->subMinutes(20)]);
    Game::factory()->create(['match_id' => $match->id, 'won' => false, 'started_at' => now()->subMinutes(10)]);

    expect(BuildOverlayState::run()['match'])->toMatchArray(['gamesWon' => 1, 'gamesLost' => 1]);
});

it('numbers the active match excluding abandoned matches', function () {
    $league = leagueWithResults(['win']);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Abandoned, 'outcome' => null, 'started_at' => now()->subHour()]);
    activeMatch($league);

    expect(BuildOverlayState::run()['match']['number'])->toBe(2);
});

it('is complete with the game record inside the grace window', function () {
    $league = leagueWithResults(['win', 'win', 'win', 'loss', 'win'], ['state' => LeagueState::Complete, 'completed_at' => now()->subMinute()]);
    foreach ($league->matches as $m) {
        Game::factory()->create(['match_id' => $m->id, 'won' => true]);
        Game::factory()->create(['match_id' => $m->id, 'won' => $m->outcome === MatchOutcome::Win]);
    }

    $state = BuildOverlayState::run();

    expect($state['status'])->toBe('complete')
        ->and($state['record'])->toBe(['wins' => 4, 'losses' => 1])
        ->and($state['gameRecord'])->toBe(['won' => 9, 'lost' => 1]);
});

it('is trophied on a perfect constructed run', function () {
    leagueWithResults(['win', 'win', 'win', 'win', 'win'], ['state' => LeagueState::Complete, 'completed_at' => now()->subMinute()]);

    expect(BuildOverlayState::run()['status'])->toBe('trophied');
});

it('is trophied on a 3-0 draft', function () {
    leagueWithResults(['win', 'win', 'win'], ['kind' => LeagueKind::Draft, 'state' => LeagueState::Complete, 'completed_at' => now()->subMinute()]);

    expect(BuildOverlayState::run()['status'])->toBe('trophied');
});

it('is dropped with progress inside the grace window', function () {
    leagueWithResults(['win', 'loss', 'loss'], ['state' => LeagueState::Dropped, 'dropped_at' => now()->subMinute()]);

    $state = BuildOverlayState::run();

    expect($state['status'])->toBe('dropped')
        ->and($state['progress'])->toBe(['played' => 3, 'of' => 5]);
});

it('is idle once the grace window has passed', function () {
    leagueWithResults(['win', 'win', 'win', 'win', 'win'], ['state' => LeagueState::Complete, 'completed_at' => now()->subMinutes(6)]);

    expect(BuildOverlayState::run()['status'])->toBe('idle');
});

it('normalises colour identity into WUBRG order without colourless', function () {
    leagueWithResults(['win'], [], overlayDeckVersion('Grixis', 'R,C,U,B'));

    expect(BuildOverlayState::run()['deck']['colorIdentity'])->toBe(['U', 'B', 'R']);
});

it('returns the compact size when set', function () {
    AppSettings::setOverlaySize('compact');

    expect(BuildOverlayState::run()['size'])->toBe('compact');
});

it('is idempotent', function () {
    $league = leagueWithResults(['win', 'loss']);
    activeMatch($league);

    expect(BuildOverlayState::run())->toBe(BuildOverlayState::run());
});

it('stays within a query budget while in game', function () {
    $league = leagueWithResults(['win', 'win', 'loss', 'win']);
    $match = activeMatch($league);
    Game::factory()->count(2)->create(['match_id' => $match->id]);

    DB::enableQueryLog();
    BuildOverlayState::run();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(10);
});

it('drops the minted date suffix from the league name', function () {
    leagueWithResults(['win'], ['name' => 'Modern League 14-09-2026 11:01am']);

    expect(BuildOverlayState::run()['event']['name'])->toBe('Modern League');
});

it('keeps a user-chosen league name as is', function () {
    leagueWithResults(['win'], ['name' => 'Friday Night 5-0 Hunt']);

    expect(BuildOverlayState::run()['event']['name'])->toBe('Friday Night 5-0 Hunt');
});

it('shows the newest active match when an older one is stuck in progress', function () {
    $league = leagueWithResults(['win']);
    MtgoMatch::factory()->inProgress()->create(['league_id' => $league->id, 'deck_version_id' => $league->deck_version_id, 'started_at' => now()->subHours(2)]);
    $live = activeMatch($league);
    Game::factory()->create(['match_id' => $live->id, 'won' => true, 'started_at' => now()->subMinutes(5)]);

    $match = BuildOverlayState::run()['match'];

    expect($match['number'])->toBe(3)
        ->and($match['games'])->toBe([['won' => true]]);
});

it('includes the stored deck label', function () {
    $league = leagueWithResults(['win']);
    AppSettings::setOverlayDeckLabel($league->deckVersion->deck_id, 'Big Mana');

    expect(BuildOverlayState::run()['deck']['label'])->toBe('Big Mana');
});

it('sends a null label when none is stored', function () {
    leagueWithResults(['win']);

    expect(BuildOverlayState::run()['deck']['label'])->toBeNull();
});

it('sends the deck archetype for the card title', function () {
    $league = leagueWithResults(['win']);
    $archetype = Archetype::factory()->create(['name' => 'Mono Green Tron']);
    $league->deckVersion->deck->update(['archetype_id' => $archetype->id]);

    expect(BuildOverlayState::run()['deck']['archetype'])->toBe('Mono Green Tron');
});

it('sends a null archetype before detection', function () {
    leagueWithResults(['win']);

    expect(BuildOverlayState::run()['deck']['archetype'])->toBeNull();
});
