<?php

use App\Jobs\SubmitMatch;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Native\Desktop\Facades\Settings;

uses(RefreshDatabase::class);

it('dispatches submit jobs asynchronously instead of blocking', function () {
    Queue::fake();
    Settings::set('share_stats', true);

    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    // Create matches that satisfy the submittable scope:
    // state=complete, submitted_at=null, deck_version_id not null, and has archetypes
    $opponent = Player::create(['username' => 'Opponent']);
    $archetype = Archetype::factory()->create();

    $matches = MtgoMatch::factory()->count(3)->create([
        'deck_version_id' => $version->id,
        'submitted_at' => null,
    ]);

    foreach ($matches as $match) {
        $game = Game::factory()->create(['match_id' => $match->id]);
        $game->players()->attach($opponent->id, [
            'instance_id' => 1,
            'is_local' => false,
            'on_play' => false,
        ]);

        MatchArchetype::create([
            'mtgo_match_id' => $match->id,
            'player_id' => $opponent->id,
            'archetype_id' => $archetype->id,
            'confidence' => 0.8,
        ]);
    }

    $this->post('/settings/submit-matches')->assertRedirect();

    Queue::assertPushed(SubmitMatch::class, 3);
});

it('does not dispatch when sharing is disabled', function () {
    Queue::fake();
    Settings::set('share_stats', false);

    $this->post('/settings/submit-matches')->assertRedirect();

    Queue::assertNothingPushed();
});
