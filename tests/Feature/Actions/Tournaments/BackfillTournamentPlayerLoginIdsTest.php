<?php

use App\Actions\Tournaments\BackfillTournamentPlayerLoginIds;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns local login_id by elimination when opponent is already known', function () {
    $localPlayer = Player::create(['username' => 'LocalUser', 'login_id' => null]);
    $opponent = Player::create(['username' => 'Opponent', 'login_id' => 2714690]);

    $match = MtgoMatch::factory()->create([
        'participant_login_ids' => [964394, 2714690],
    ]);

    $game = Game::factory()->for($match, 'match')->create();
    $game->players()->attach($localPlayer->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

    BackfillTournamentPlayerLoginIds::run($match);

    expect($localPlayer->fresh()->login_id)->toBe(964394);
    expect($opponent->fresh()->login_id)->toBe(2714690);
});

it('assigns opponent login_id by elimination when local is already known', function () {
    $localPlayer = Player::create(['username' => 'LocalUser', 'login_id' => 964394]);
    $opponent = Player::create(['username' => 'Opponent', 'login_id' => null]);

    $match = MtgoMatch::factory()->create([
        'participant_login_ids' => [964394, 2714690],
    ]);

    $game = Game::factory()->for($match, 'match')->create();
    $game->players()->attach($localPlayer->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

    BackfillTournamentPlayerLoginIds::run($match);

    expect($opponent->fresh()->login_id)->toBe(2714690);
});

it('skips when neither player login_id is known', function () {
    $localPlayer = Player::create(['username' => 'LocalUser', 'login_id' => null]);
    $opponent = Player::create(['username' => 'Opponent', 'login_id' => null]);

    $match = MtgoMatch::factory()->create([
        'participant_login_ids' => [964394, 2714690],
    ]);

    $game = Game::factory()->for($match, 'match')->create();
    $game->players()->attach($localPlayer->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

    BackfillTournamentPlayerLoginIds::run($match);

    expect($localPlayer->fresh()->login_id)->toBeNull();
    expect($opponent->fresh()->login_id)->toBeNull();
});

it('is idempotent and does not overwrite a correct login_id', function () {
    $localPlayer = Player::create(['username' => 'LocalUser', 'login_id' => 964394]);
    $opponent = Player::create(['username' => 'Opponent', 'login_id' => 2714690]);

    $match = MtgoMatch::factory()->create([
        'participant_login_ids' => [964394, 2714690],
    ]);

    $game = Game::factory()->for($match, 'match')->create();
    $game->players()->attach($localPlayer->id, ['is_local' => true, 'instance_id' => 1, 'on_play' => true]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2, 'on_play' => false]);

    BackfillTournamentPlayerLoginIds::run($match);
    BackfillTournamentPlayerLoginIds::run($match);

    expect($localPlayer->fresh()->login_id)->toBe(964394);
    expect($opponent->fresh()->login_id)->toBe(2714690);
});
