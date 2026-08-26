<?php

use App\Actions\Limited\Read\GetLimitedEventSharedProps;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds sidebar props for a draft league', function () {
    $league = League::factory()->create([
        'kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'event_id' => 11039, 'state' => LeagueState::Complete,
        'started_at' => now()->parse('2026-08-22 11:00:12'),
    ]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id, 'seat_index' => 5, 'seat_count' => 8]);
    Card::factory()->create(['mtgo_id' => '154000', 'name' => 'Bard the Bowman', 'set_name' => 'The Hobbit', 'rarity' => 'uncommon', 'art_crop' => 'https://img/bard.jpg']);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'picked_catalog_id' => 154000]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 2, 'picked_catalog_id' => 154001]);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Win]);
    MtgoMatch::factory()->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'outcome' => MatchOutcome::Loss]);

    $event = GetLimitedEventSharedProps::run($league)['event'];

    expect($event->title)->toBe('HOB Draft · 22 Aug')
        ->and($event->subtitle)->toBe('The Hobbit · League 11039')
        ->and($event->wins)->toBe(1)->and($event->losses)->toBe(1)
        ->and($event->picksMade)->toBe(2)->and($event->picksExpected)->toBe(42)
        ->and($event->state)->toBe('Complete')
        ->and($event->coverArt)->toBe('https://img/bard.jpg')
        ->and($event->seatIndex)->toBe(5)
        ->and($event->deckRegistered)->toBeFalse()
        ->and($event->draftState)->toBe('finished');
});

it('prefers the earliest rare or mythic pick for cover art', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'started_at' => now()]);
    $draft = Draft::factory()->finished()->create(['league_id' => $league->id]);
    Card::factory()->create(['mtgo_id' => '154010', 'name' => 'Gandalf the Grey', 'set_name' => 'The Hobbit', 'rarity' => 'mythic', 'art_crop' => 'https://img/gandalf.jpg']);
    Card::factory()->create(['mtgo_id' => '154020', 'name' => 'Smaug', 'set_name' => 'The Hobbit', 'rarity' => 'rare', 'art_crop' => 'https://img/smaug.jpg']);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 1, 'picked_catalog_id' => 154010]);
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 5, 'picked_catalog_id' => 154020]);

    $event = GetLimitedEventSharedProps::run($league)['event'];

    expect($event->coverArt)->toBe('https://img/gandalf.jpg');
});

it('tolerates a league without a draft', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Sealed, 'set_code' => null, 'started_at' => now()]);

    $event = GetLimitedEventSharedProps::run($league)['event'];

    expect($event->title)->toContain('Sealed')
        ->and($event->picksMade)->toBe(0)
        ->and($event->coverArt)->toBeNull()
        ->and($event->draftState)->toBe('none');
});
