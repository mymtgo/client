<?php

use App\Actions\Matches\ParseGameLogBinary;
use App\Models\Account;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use App\Updates\BackfillGameMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// clean_2_0_win.dat has two games with players 'anticloser' and 'Bordas99'.
// Game 0: dice_rolls {anticloser:6, Bordas99:2}, mulligans {anticloser:2, Bordas99:0}, turn_count:6
// Game 1: dice_rolls [], mulligans {anticloser:1, Bordas99:1}, turn_count:9
// We treat 'anticloser' as the local account, 'Bordas99' as the opponent.

const BGM_LOCAL = 'anticloser';
const BGM_OPP = 'Bordas99';

beforeEach(function () {
    Account::flushCurrent();
});

function bgm_setup(int $gameCount = 2): array
{
    $account = Account::factory()->create(['username' => BGM_LOCAL, 'active' => true]);
    $opp = Opponent::factory()->create(['username' => BGM_OPP]);
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'state' => 'complete',
        'deck_version_id' => $deckVersion->id,
        'account_id' => $account->id,
        'opponent_id' => $opp->id,
    ]);

    $games = [];
    for ($i = 0; $i < $gameCount; $i++) {
        $games[] = Game::factory()->for($match, 'match')->create([
            'started_at' => now()->addSeconds($i * 600),
        ]);
    }

    // Seed a GameLog with real decoded_entries from the fixture
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_0_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    GameLog::create([
        'match_token' => $match->token,
        'file_path' => 'tests/fixtures/gamelogs/clean_2_0_win.dat',
        'decoded_entries' => $parsed['entries'],
    ]);

    return [$match, $games];
}

it('writes local and opponent dice rolls onto the games row', function () {
    [, $games] = bgm_setup();

    (new BackfillGameMetadata)->run();

    $games[0]->refresh();

    // anticloser (local) rolled 6, Bordas99 (opp) rolled 2
    expect($games[0]->local_dice)->toBe(6);
    expect($games[0]->opp_dice)->toBe(2);
});

it('writes local and opponent mulligans onto the games row', function () {
    [, $games] = bgm_setup();

    (new BackfillGameMetadata)->run();

    $games[0]->refresh();
    $games[1]->refresh();

    // Game 0: anticloser 2 mulls, Bordas99 0 mulls
    expect($games[0]->local_mulligans)->toBe(2);
    expect($games[0]->opp_mulligans)->toBe(0);

    // Game 1: anticloser 1 mull, Bordas99 1 mull; no dice
    expect($games[1]->local_mulligans)->toBe(1);
    expect($games[1]->opp_mulligans)->toBe(1);
    expect($games[1]->local_dice)->toBeNull();
    expect($games[1]->opp_dice)->toBeNull();
});

it('writes turn_count onto the game row', function () {
    [, $games] = bgm_setup();

    (new BackfillGameMetadata)->run();

    $games[0]->refresh();
    $games[1]->refresh();

    expect($games[0]->turn_count)->toBe(6);
    expect($games[1]->turn_count)->toBe(9);
});

it('skips matches without a decoded game log', function () {
    $account = Account::factory()->create(['username' => BGM_LOCAL, 'active' => true]);
    $opp = Opponent::factory()->create(['username' => BGM_OPP]);
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'state' => 'complete',
        'deck_version_id' => $deckVersion->id,
        'account_id' => $account->id,
        'opponent_id' => $opp->id,
    ]);
    $game = Game::factory()->for($match, 'match')->create(['started_at' => now()]);

    // No GameLog row seeded

    (new BackfillGameMetadata)->run();

    $game->refresh();
    expect($game->local_mulligans)->toBeNull();
    expect($game->opp_mulligans)->toBeNull();
});
