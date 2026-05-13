<?php

use App\Actions\Logs\ParseMetaMessage;
use App\Enums\MetaMessageKind;

it('parses a die roll chat message', function () {
    // "@Phyuma rolled a 1."
    $bytes = [43, 0, 0, 0, 3, 17, 186, 129, 118, 228, 151, 56, 103, 0, 0, 0, 0, 0, 0, 0, 19, 0, 0, 0,
        64, 80, 104, 121, 117, 109, 97, 32, 114, 111, 108, 108, 101, 100, 32, 97, 32, 49, 46];

    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed)->not->toBeNull()
        ->and($parsed['type'])->toBe(3)
        ->and($parsed['kind'])->toBe(MetaMessageKind::DieRoll->value)
        ->and($parsed['event']['action'])->toBe('die_roll')
        ->and($parsed['event']['player'])->toBe('hyuma')
        ->and($parsed['event']['value'])->toBe(1);
});

it('parses a play-choice chat message', function () {
    // "@Pluizhenriquebimbo chooses to play first."
    $msg = '@Pluizhenriquebimbo chooses to play first.';
    $bytes = array_merge(
        [strlen($msg) + 24, 0, 0, 0, 3, 17, 186, 129, 118, 228, 151, 56, 103, 0, 0, 0, 0, 0, 0, 0, strlen($msg), 0, 0, 0],
        array_map('ord', str_split($msg)),
    );

    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed['kind'])->toBe(MetaMessageKind::PlayChoice->value)
        ->and($parsed['event']['player'])->toBe('luizhenriquebimbo')
        ->and($parsed['event']['value'])->toBe('play');
});

it('parses a mulligan chat message', function () {
    $msg = '@Phyuma mulligans to six.';
    $bytes = array_merge(
        [strlen($msg) + 24, 0, 0, 0, 3, 17, 186, 129, 118, 228, 151, 56, 103, 0, 0, 0, 0, 0, 0, 0, strlen($msg), 0, 0, 0],
        array_map('ord', str_split($msg)),
    );

    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed['kind'])->toBe(MetaMessageKind::Mulligan->value)
        ->and($parsed['event']['player'])->toBe('hyuma')
        ->and($parsed['event']['value'])->toBe(6);
});

it('parses a game winner chat message', function () {
    $msg = '@Phyuma wins the game.';
    $bytes = array_merge(
        [strlen($msg) + 24, 0, 0, 0, 3, 17, 186, 129, 118, 228, 151, 56, 103, 0, 0, 0, 0, 0, 0, 0, strlen($msg), 0, 0, 0],
        array_map('ord', str_split($msg)),
    );

    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed['kind'])->toBe(MetaMessageKind::GameWinner->value)
        ->and($parsed['event']['player'])->toBe('hyuma');
});

it('parses a concede chat message', function () {
    $msg = '@Pluiz has conceded from the game.';
    $bytes = array_merge(
        [strlen($msg) + 24, 0, 0, 0, 3, 17, 186, 129, 118, 228, 151, 56, 103, 0, 0, 0, 0, 0, 0, 0, strlen($msg), 0, 0, 0],
        array_map('ord', str_split($msg)),
    );

    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed['kind'])->toBe(MetaMessageKind::Concede->value)
        ->and($parsed['event']['player'])->toBe('luiz');
});

it('parses a cast card chat message', function () {
    $msg = '@Pluiz casts @[Ichor Wellspring@:78678,447:@].';
    $bytes = array_merge(
        [strlen($msg) + 24, 0, 0, 0, 3, 17, 186, 129, 118, 228, 151, 56, 103, 0, 0, 0, 0, 0, 0, 0, strlen($msg), 0, 0, 0],
        array_map('ord', str_split($msg)),
    );

    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed['kind'])->toBe(MetaMessageKind::CastCard->value)
        ->and($parsed['event']['player'])->toBe('luiz')
        ->and($parsed['event']['card']['name'])->toBe('Ichor Wellspring')
        ->and($parsed['event']['card']['multiverse_id'])->toBe(78678)
        ->and($parsed['event']['card']['instance_id'])->toBe(447);
});

it('parses a turn start chat message', function () {
    $msg = '@PTurn 3: luiz';
    $bytes = array_merge(
        [strlen($msg) + 24, 0, 0, 0, 3, 17, 186, 129, 118, 228, 151, 56, 103, 0, 0, 0, 0, 0, 0, 0, strlen($msg), 0, 0, 0],
        array_map('ord', str_split($msg)),
    );

    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed['kind'])->toBe(MetaMessageKind::TurnStart->value)
        ->and($parsed['event']['player'])->toBe('luiz')
        ->and($parsed['event']['value'])->toBe(3);
});

it('returns null for too-short bytes', function () {
    expect(ParseMetaMessage::run([1, 2, 3]))->toBeNull();
});

it('returns unknown kind for unrecognised type byte', function () {
    $bytes = [10, 0, 0, 0, 250, 0, 0, 0, 0, 0, 0, 0, 0, 0];
    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed['type'])->toBe(250)
        ->and($parsed['kind'])->toBe(MetaMessageKind::Unknown->value);
});

it('parses a deck list (type 82)', function () {
    $header = [108, 2, 0, 0, 82, 18, 181, 208, 118, 228, 151, 56, 60, 0, 0, 0];
    // 60 u64-LE multiverse IDs — use a simple repeating pattern: 100..159
    $cardBytes = [];
    for ($i = 100; $i < 160; $i++) {
        $cardBytes[] = $i & 0xFF;
        $cardBytes[] = ($i >> 8) & 0xFF;
        for ($j = 0; $j < 6; $j++) {
            $cardBytes[] = 0;
        }
    }
    $bytes = array_merge($header, $cardBytes);

    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed['kind'])->toBe(MetaMessageKind::DeckList->value)
        ->and($parsed['cards'])->toHaveCount(60)
        ->and($parsed['cards'][0])->toBe(100)
        ->and($parsed['cards'][59])->toBe(159);
});

it('parses an opponent name (type 4)', function () {
    $name = 'OpponentX';
    $bytes = array_merge(
        [strlen($name) + 24, 0, 0, 0, 4, 17, 186, 129, 118, 228, 151, 56, 0, 0, 0, 0, 0, 0, 0, 0, strlen($name), 0, 0, 0],
        array_map('ord', str_split($name)),
    );

    $parsed = ParseMetaMessage::run($bytes);

    expect($parsed['kind'])->toBe(MetaMessageKind::OpponentName->value)
        ->and($parsed['text'])->toBe($name);
});
