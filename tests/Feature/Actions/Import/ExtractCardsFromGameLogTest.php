<?php

use App\Actions\Import\ExtractCardsFromGameLog;
use App\Actions\Matches\ParseGameLogBinary;

it('extracts cards for each player from game log entries', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_0_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    expect($result)->toHaveKeys(['players', 'cards_by_player']);
    expect($result['players'])->toBeArray()->not->toBeEmpty();

    foreach ($result['players'] as $player) {
        expect($result['cards_by_player'][$player])->toBeArray();
    }

    $firstPlayer = $result['players'][0];
    $firstCard = $result['cards_by_player'][$firstPlayer][0] ?? null;
    expect($firstCard)->not->toBeNull();
    expect($firstCard)->toHaveKeys(['mtgo_id', 'name']);
    expect($firstCard['mtgo_id'])->toBeInt();
    expect($firstCard['name'])->toBeString()->not->toBeEmpty();
});

it('returns empty cards for instant concede games', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/instant_concede.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    expect($result['players'])->toBeArray();
});

it('deduplicates cards by mtgo_id per player', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_1_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    foreach ($result['players'] as $player) {
        $mtgoIds = array_column($result['cards_by_player'][$player], 'mtgo_id');
        expect($mtgoIds)->toBe(array_unique($mtgoIds));
    }
});

it('returns per-game cards split by game boundaries', function () {
    // clean_2_1_win.dat has 3 games
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_1_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    expect($result)->toHaveKey('cards_by_game');
    expect($result['cards_by_game'])->toBeArray();

    // Should have per-game entries (3 games in this fixture)
    expect(count($result['cards_by_game']))->toBeGreaterThanOrEqual(2);

    // Each game should have cards keyed by player
    foreach ($result['cards_by_game'] as $gameCards) {
        expect($gameCards)->toBeArray();
        foreach ($gameCards as $player => $cards) {
            expect($cards)->toBeArray();
            foreach ($cards as $card) {
                expect($card)->toHaveKeys(['mtgo_id', 'name']);
            }
        }
    }

    // Per-game cards should deduplicate within each game
    foreach ($result['cards_by_game'] as $gameCards) {
        foreach ($gameCards as $cards) {
            $mtgoIds = array_column($cards, 'mtgo_id');
            expect($mtgoIds)->toBe(array_unique($mtgoIds));
        }
    }
});

it('includes cast count on each card entry', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_0_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    $firstPlayer = $result['players'][0];
    $firstCard = $result['cards_by_player'][$firstPlayer][0];
    expect($firstCard)->toHaveKeys(['mtgo_id', 'name', 'cast']);
    expect($firstCard['cast'])->toBeInt();
});

it('counts casts and plays as cast actions', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_0_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    $firstPlayer = $result['players'][0];
    $allCards = $result['cards_by_player'][$firstPlayer];
    $castCards = array_filter($allCards, fn ($c) => $c['cast'] > 0);

    expect($castCards)->not->toBeEmpty();
});

it('includes cast counts in per-game card breakdowns', function () {
    $raw = file_get_contents(base_path('tests/fixtures/gamelogs/clean_2_0_win.dat'));
    $parsed = ParseGameLogBinary::run($raw);
    $entries = $parsed['entries'];

    $result = ExtractCardsFromGameLog::run($entries);

    foreach ($result['cards_by_game'] as $gameCards) {
        foreach ($gameCards as $cards) {
            foreach ($cards as $card) {
                expect($card)->toHaveKeys(['mtgo_id', 'name', 'cast']);
            }
        }
    }
});

it('does not attribute a planeswalker to the player removing loyalty counters via combat', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PBravo casts @[Karn, the Great Creator@:155958,100:@].'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PAlpha removes a loyalty counter from @[Karn, the Great Creator@:155958,100:@].'],
    ];

    $result = ExtractCardsFromGameLog::run($entries);

    $alphaIds = collect($result['cards_by_player']['Alpha'])->pluck('mtgo_id')->toArray();
    $bravoIds = collect($result['cards_by_player']['Bravo'])->pluck('mtgo_id')->toArray();

    expect($alphaIds)->not->toContain(77979);
    expect($bravoIds)->toContain(77979);
});

it('does not attribute the ability source to the affected player in exiles-with messages', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PBravo casts @[Subtlety@:181014,200:@] by exiling a blue card from your hand with evoke.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PAlpha exiles @[Sowing Mycospawn@:251694,201:@] with @[Subtlety@:181014,200:@]\'s ability.'],
    ];

    $result = ExtractCardsFromGameLog::run($entries);

    $alphaIds = collect($result['cards_by_player']['Alpha'])->pluck('mtgo_id')->toArray();
    $bravoIds = collect($result['cards_by_player']['Bravo'])->pluck('mtgo_id')->toArray();

    expect($alphaIds)->not->toContain(90507);
    expect($bravoIds)->toContain(90507);
});

it('does not attribute the ability source to the affected player in returns-with messages', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PBravo casts @[Teferi, Time Raveler@:143200,300:@].'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PAlpha returns @[Sowing Mycospawn@:251694,301:@] to its owner\'s hand with @[Teferi, Time Raveler@:143200,300:@]\'s ability.'],
    ];

    $result = ExtractCardsFromGameLog::run($entries);

    $alphaIds = collect($result['cards_by_player']['Alpha'])->pluck('mtgo_id')->toArray();
    $bravoIds = collect($result['cards_by_player']['Bravo'])->pluck('mtgo_id')->toArray();

    expect($alphaIds)->not->toContain(71600);
    expect($bravoIds)->toContain(71600);
});

it('separates land plays from spell casts', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PAlpha plays @[Forest@:281774,100:@].'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PAlpha casts @[Llanowar Elves@:1000,101:@].'],
    ];
    $result = ExtractCardsFromGameLog::run($entries);
    $alphaCards = collect($result['cards_by_player']['Alpha']);
    $forest = $alphaCards->firstWhere('name', 'Forest');
    expect($forest['cast'])->toBe(0);
    expect($forest['played'])->toBe(1);
    $elves = $alphaCards->firstWhere('name', 'Llanowar Elves');
    expect($elves['cast'])->toBe(1);
    expect($elves['played'])->toBe(0);
});

it('tracks kicked casts', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PAlpha casts @[Sowing Mycospawn@:251694,100:@] with kicker.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PAlpha casts @[Sowing Mycospawn@:251694,101:@].'],
    ];
    $result = ExtractCardsFromGameLog::run($entries);
    $card = collect($result['cards_by_player']['Alpha'])->firstWhere('name', 'Sowing Mycospawn');
    expect($card['cast'])->toBe(2);
    expect($card['kicked'])->toBe(1);
});

it('tracks flashback casts', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PAlpha casts @[Eviscerator\'s Insight@:252406,100:@] with Flashback {4B} from the graveyard.'],
    ];
    $result = ExtractCardsFromGameLog::run($entries);
    $card = collect($result['cards_by_player']['Alpha'])->firstWhere('name', "Eviscerator's Insight");
    expect($card['cast'])->toBe(1);
    expect($card['flashback'])->toBe(1);
});

it('tracks madness casts', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PAlpha casts @[Fiery Temper@:119868,100:@] by paying {R} with Madness {R} targeting Bravo.'],
    ];
    $result = ExtractCardsFromGameLog::run($entries);
    $card = collect($result['cards_by_player']['Alpha'])->firstWhere('name', 'Fiery Temper');
    expect($card['cast'])->toBe(1);
    expect($card['madness'])->toBe(1);
});

it('tracks evoke casts', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PAlpha casts @[Solitude@:182406,100:@] by exiling a white card from your hand with evoke.'],
    ];
    $result = ExtractCardsFromGameLog::run($entries);
    $card = collect($result['cards_by_player']['Alpha'])->firstWhere('name', 'Solitude');
    expect($card['cast'])->toBe(1);
    expect($card['evoked'])->toBe(1);
});

it('tracks activated ability count', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PAlpha casts @[Karn, the Great Creator@:155958,100:@].'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PAlpha activates an ability of @[Karn, the Great Creator@:155958,100:@] (You may reveal an artifact card...).'],
        ['timestamp' => '2026-01-01T00:00:03+00:00', 'message' => '@PAlpha activates an ability of @[Karn, the Great Creator@:155958,100:@] (You may reveal an artifact card...).'],
    ];
    $result = ExtractCardsFromGameLog::run($entries);
    $card = collect($result['cards_by_player']['Alpha'])->firstWhere('name', 'Karn, the Great Creator');
    expect($card['cast'])->toBe(1);
    expect($card['activated'])->toBe(2);
});

it('extracts dice rolls per player per game', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@PAlpha rolled a 5.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@PBravo rolled a 3.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PAlpha begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PBravo begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PAlpha wins the game.'],
    ];
    $result = ExtractCardsFromGameLog::run($entries);
    expect($result['game_meta'])->toHaveKey(0);
    expect($result['game_meta'][0]['dice_rolls'])->toBe(['Alpha' => 5, 'Bravo' => 3]);
});

it('extracts mulligan count per player', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PAlpha mulligans to six cards.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PAlpha begins the game with six cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PBravo begins the game with seven cards in hand.'],
        ['timestamp' => '2026-01-01T00:00:03+00:00', 'message' => '@PAlpha wins the game.'],
    ];
    $result = ExtractCardsFromGameLog::run($entries);
    expect($result['game_meta'][0]['mulligans'])->toBe(['Alpha' => 1, 'Bravo' => 0]);
});

it('extracts turn count from turn markers', function () {
    $entries = [
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PAlpha joined the game.'],
        ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@PBravo joined the game.'],
        ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@PTurn 1: Alpha'],
        ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@PTurn 1: Bravo'],
        ['timestamp' => '2026-01-01T00:00:03+00:00', 'message' => '@PTurn 2: Alpha'],
        ['timestamp' => '2026-01-01T00:00:04+00:00', 'message' => '@PTurn 2: Bravo'],
        ['timestamp' => '2026-01-01T00:00:05+00:00', 'message' => '@PTurn 3: Alpha'],
        ['timestamp' => '2026-01-01T00:00:06+00:00', 'message' => '@PAlpha wins the game.'],
    ];
    $result = ExtractCardsFromGameLog::run($entries);
    expect($result['game_meta'][0]['turn_count'])->toBe(3);
});
