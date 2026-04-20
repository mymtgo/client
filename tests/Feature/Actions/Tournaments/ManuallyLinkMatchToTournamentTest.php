<?php

use App\Actions\Tournaments\ManuallyLinkMatchToTournament;
use App\Enums\MatchState;
use App\Enums\TournamentType;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeMatchWithPlayers(?int $localLoginId, ?int $opponentLoginId): MtgoMatch
{
    $match = MtgoMatch::factory()->create(['state' => MatchState::Complete]);
    $game = Game::factory()->create(['match_id' => $match->id]);

    $local = Player::factory()->create(['login_id' => $localLoginId]);
    $opponent = Player::factory()->create(['login_id' => $opponentLoginId]);

    $game->players()->attach($local->id, ['is_local' => true, 'instance_id' => 1]);
    $game->players()->attach($opponent->id, ['is_local' => false, 'instance_id' => 2]);

    return $match;
}

it('links a match to a tournament and writes round + login ids', function () {
    $tournament = Tournament::factory()->create(['participated' => true, 'type' => TournamentType::Constructed]);
    $match = makeMatchWithPlayers(964394, 2714690);

    ManuallyLinkMatchToTournament::link($match, $tournament, 3);

    $match->refresh();
    expect($match->tournament_id)->toBe($tournament->id);
    expect($match->tournament_round)->toBe(3);
    expect($match->participant_login_ids)->toEqualCanonicalizing([964394, 2714690]);
});

it('overwrites a previous link when relinking', function () {
    $old = Tournament::factory()->create(['participated' => true]);
    $new = Tournament::factory()->create(['participated' => true]);
    $match = makeMatchWithPlayers(1, 2);
    $match->update(['tournament_id' => $old->id, 'tournament_round' => 5]);

    ManuallyLinkMatchToTournament::link($match, $new, 2);

    $match->refresh();
    expect($match->tournament_id)->toBe($new->id);
    expect($match->tournament_round)->toBe(2);
});

it('writes empty login id array when players have no login_id', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);
    $match = makeMatchWithPlayers(null, null);

    ManuallyLinkMatchToTournament::link($match, $tournament, 1);

    $match->refresh();
    expect($match->participant_login_ids)->toBe([]);
});

it('unlinks a match', function () {
    $tournament = Tournament::factory()->create(['participated' => true]);
    $match = makeMatchWithPlayers(1, 2);
    $match->update([
        'tournament_id' => $tournament->id,
        'tournament_round' => 4,
        'participant_login_ids' => [1, 2],
    ]);

    ManuallyLinkMatchToTournament::unlink($match);

    $match->refresh();
    expect($match->tournament_id)->toBeNull();
    expect($match->tournament_round)->toBeNull();
    expect($match->participant_login_ids)->toBeNull();

    expect(Tournament::find($tournament->id))->not->toBeNull();
});
