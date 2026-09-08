<?php

use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create(['username' => 'tester', 'active' => true, 'tracked' => true]);
    Account::flushCurrent();
    $this->archetype = Archetype::factory()->create(['format' => 'modern', 'name' => 'Deck Arch']);
    $this->deck = Deck::factory()->create([
        'account_id' => $this->account->id,
        'format' => 'CMODERN',
        'archetype_id' => $this->archetype->id,
    ]);
    DeckVersion::factory()->create(['deck_id' => $this->deck->id, 'modified_at' => now()->subDays(3)]);
    $this->latest = DeckVersion::factory()->create(['deck_id' => $this->deck->id, 'modified_at' => now()->subDay()]);
    $this->opponentArchetype = Archetype::factory()->create(['format' => 'modern', 'name' => 'Opp Arch']);
});

function manualMatchPayload(array $overrides = []): array
{
    return array_merge([
        'deck_id' => test()->deck->id,
        'opponent_name' => 'Kai',
        'archetype_id' => test()->opponentArchetype->id,
        'league_id' => null,
        'started_at' => '2026-09-01T20:00',
        'ended_at' => '2026-09-01T20:45',
        'games' => [
            ['won' => true, 'on_play' => true],
            ['won' => false, 'on_play' => false],
            ['won' => true, 'on_play' => true],
        ],
    ], $overrides);
}

it('creates a complete manual match with games, players and archetypes', function () {
    $this->post('/matches', manualMatchPayload())->assertRedirect()->assertSessionHas('success');

    $match = MtgoMatch::query()->latest('id')->first();

    expect($match->manual)->toBeTrue()
        ->and($match->imported)->toBeFalse()
        ->and($match->state)->toBe(MatchState::Complete)
        ->and($match->outcome)->toBe(MatchOutcome::Win)
        ->and($match->games_won)->toBe(2)
        ->and($match->games_lost)->toBe(1)
        ->and($match->deck_version_id)->toBe($this->latest->id)
        ->and($match->format)->toBe('CMODERN')
        ->and($match->match_type)->toBe('Constructed')
        ->and($match->mtgo_id)->toStartWith('manual_')
        ->and($match->submitted_at)->toBeNull()
        ->and($match->started_at->toDateTimeString())->toBe('2026-09-01 20:00:00')
        ->and($match->ended_at->toDateTimeString())->toBe('2026-09-01 20:45:00');

    $games = $match->games()->orderBy('started_at')->get();
    expect($games)->toHaveCount(3)
        ->and($games->pluck('won')->all())->toBe([true, false, true])
        ->and($games->pluck('started_at')->map->toDateTimeString()->all())->toBe(['2026-09-01 20:00:00', '2026-09-01 20:15:00', '2026-09-01 20:30:00']);

    $local = $games[0]->players->first(fn ($p) => $p->pivot->is_local);
    $opponent = $games[0]->players->first(fn ($p) => ! $p->pivot->is_local);
    expect((bool) $local->pivot->on_play)->toBeTrue()
        ->and((bool) $opponent->pivot->on_play)->toBeFalse()
        ->and($opponent->username)->toBe('Kai')
        ->and((bool) $games[1]->players->first(fn ($p) => $p->pivot->is_local)->pivot->on_play)->toBeFalse();

    $opponentArchetype = $match->opponentArchetypes()->first();
    expect($opponentArchetype->archetype_id)->toBe($this->opponentArchetype->id)
        ->and($opponentArchetype->player_id)->toBe($opponent->id)
        ->and($opponentArchetype->manual)->toBeTrue();
    expect($match->archetypes()->where('player_id', $local->id)->value('archetype_id'))->toBe($this->archetype->id);
});

it('reads the zone-less form times in the system timezone and stores UTC', function () {
    AppSettings::setSystemTimezone('Europe/London');

    $this->post('/matches', manualMatchPayload([
        'started_at' => '2026-09-01T20:00',
        'ended_at' => '2026-09-01T20:45',
    ]))->assertRedirect();

    $match = MtgoMatch::query()->latest('id')->first();

    expect($match->started_at->toDateTimeString())->toBe('2026-09-01 19:00:00')
        ->and($match->ended_at->toDateTimeString())->toBe('2026-09-01 19:45:00')
        ->and($match->started_at->toLocal()->format('H:i'))->toBe('20:00');
});

it('records a draw for a 1-1 match and skips the opponent archetype when none is given', function () {
    $this->post('/matches', manualMatchPayload([
        'archetype_id' => null,
        'games' => [['won' => true, 'on_play' => true], ['won' => false, 'on_play' => false]],
    ]))->assertRedirect();

    $match = MtgoMatch::query()->latest('id')->first();

    expect($match->outcome)->toBe(MatchOutcome::Draw)
        ->and($match->opponentArchetypes()->count())->toBe(0);
});

it('reuses an existing opponent player by name', function () {
    $existing = Player::create(['username' => 'Kai']);

    $this->post('/matches', manualMatchPayload())->assertRedirect();

    expect(Player::where('username', 'Kai')->count())->toBe(1);
    $match = MtgoMatch::query()->latest('id')->first();
    expect($match->games[0]->players->pluck('id'))->toContain($existing->id);
});

it('files the match under a league using the league deck version and completes a full run', function () {
    $leagueVersion = DeckVersion::factory()->create(['deck_id' => $this->deck->id, 'modified_at' => now()->subDays(10)]);
    $league = League::factory()->create(['deck_version_id' => $leagueVersion->id, 'state' => LeagueState::Active]);
    MtgoMatch::factory()->count(4)->create(['league_id' => $league->id, 'deck_version_id' => $leagueVersion->id, 'state' => MatchState::Complete]);

    $this->post('/matches', manualMatchPayload(['league_id' => $league->id]))->assertRedirect();

    $match = MtgoMatch::query()->latest('id')->first();
    expect($match->league_id)->toBe($league->id)
        ->and($match->match_type)->toBe('League')
        ->and($match->deck_version_id)->toBe($leagueVersion->id)
        ->and($league->fresh()->state)->toBe(LeagueState::Complete);
});

it('rejects a league already holding a full run', function () {
    $league = League::factory()->create(['deck_version_id' => $this->latest->id]);
    MtgoMatch::factory()->count(5)->create(['league_id' => $league->id, 'deck_version_id' => $this->latest->id, 'state' => MatchState::Complete]);

    $this->post('/matches', manualMatchPayload(['league_id' => $league->id]))->assertSessionHasErrors('league_id');
    expect(MtgoMatch::where('manual', true)->count())->toBe(0);
});

it('rejects a league played with a different deck', function () {
    $otherVersion = DeckVersion::factory()->create();
    $league = League::factory()->create(['deck_version_id' => $otherVersion->id]);

    $this->post('/matches', manualMatchPayload(['league_id' => $league->id]))->assertSessionHasErrors('league_id');
});

it('rejects impossible game records', function () {
    $this->post('/matches', manualMatchPayload([
        'games' => [['won' => true, 'on_play' => true], ['won' => true, 'on_play' => true], ['won' => true, 'on_play' => true]],
    ]))->assertSessionHasErrors('games');

    $this->post('/matches', manualMatchPayload(['games' => []]))->assertSessionHasErrors('games');

    // 2-0 then a third game
    $this->post('/matches', manualMatchPayload([
        'games' => [['won' => true, 'on_play' => true], ['won' => true, 'on_play' => false], ['won' => false, 'on_play' => true]],
    ]))->assertSessionHasErrors('games');

    // 2-1 is fine
    $this->post('/matches', manualMatchPayload([
        'games' => [['won' => true, 'on_play' => true], ['won' => false, 'on_play' => false], ['won' => true, 'on_play' => true]],
    ]))->assertSessionDoesntHaveErrors();
});

it('rejects an end time before the start time and a deck with no version', function () {
    $this->post('/matches', manualMatchPayload(['ended_at' => '2026-09-01T19:00']))->assertSessionHasErrors('ended_at');

    $bare = Deck::factory()->create(['account_id' => $this->account->id, 'format' => 'CMODERN']);
    $this->post('/matches', manualMatchPayload(['deck_id' => $bare->id]))->assertSessionHasErrors('deck_id');
});

it('rejects decks from another account', function () {
    $other = Account::create(['username' => 'other', 'active' => false, 'tracked' => false]);
    Account::flushCurrent();
    $foreign = Deck::factory()->create(['account_id' => $other->id]);
    DeckVersion::factory()->create(['deck_id' => $foreign->id]);

    $this->post('/matches', manualMatchPayload(['deck_id' => $foreign->id]))->assertSessionHasErrors('deck_id');
});

it('creates two matches for the same payload posted twice', function () {
    $this->post('/matches', manualMatchPayload());
    $this->post('/matches', manualMatchPayload());

    expect(MtgoMatch::where('manual', true)->count())->toBe(2);
});
