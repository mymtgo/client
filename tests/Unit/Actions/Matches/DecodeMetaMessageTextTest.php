<?php

use App\Actions\Matches\DecodeMetaMessageText;

it('extracts player roll text', function () {
    // From fixture mtgo.log line 1249, real bytes
    $bytes = [48, 0, 0, 0, 3, 17, 186, 129, 94, 148, 91, 56, 103, 0, 0, 0, 0, 0, 0, 0, 24, 0, 0, 0,
        64, 80, 97, 110, 116, 105, 99, 108, 111, 115, 101, 114, 32, 114, 111, 108, 108, 101, 100, 32, 97, 32, 52, 46];

    expect(DecodeMetaMessageText::run($bytes))->toBe('@Panticloser rolled a 4.');
});

it('extracts player joined text with double @P prefix', function () {
    $bytes = [50, 0, 0, 0, 3, 17, 186, 129, 118, 228, 151, 56, 103, 0, 0, 0, 0, 0, 0, 0, 26, 0, 0, 0,
        64, 80, 64, 80, 97, 110, 116, 105, 99, 108, 111, 115, 101, 114, 32, 106, 111, 105, 110, 101, 100, 32, 116, 104, 101, 32, 103, 97, 109, 101, 46];

    expect(DecodeMetaMessageText::run($bytes))->toBe('@P@Panticloser joined the game.');
});

it('extracts chooses-to-play text', function () {
    $bytes = [61, 0, 0, 0, 3, 17, 186, 129, 94, 148, 91, 56, 103, 0, 0, 0, 0, 0, 0, 0, 37, 0, 0, 0,
        64, 80, 69, 114, 105, 100, 97, 110, 65, 109, 112, 111, 114, 97, 32, 99, 104, 111, 111, 115, 101, 115, 32, 116, 111, 32, 112, 108, 97, 121, 32, 102, 105, 114, 115, 116, 46];

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PEridanAmpora chooses to play first.');
});

it('extracts begins-with-N-cards text', function () {
    $bytes = [80, 0, 0, 0, 3, 17, 186, 129, 94, 148, 91, 56, 103, 0, 0, 0, 0, 0, 0, 0, 56, 0, 0, 0,
        64, 80, 69, 114, 105, 100, 97, 110, 65, 109, 112, 111, 114, 97, 32, 98, 101, 103, 105, 110, 115, 32, 116, 104, 101, 32, 103, 97, 109, 101, 32, 119, 105, 116, 104, 32, 115, 101, 118, 101, 110, 32, 99, 97, 114, 100, 115, 32, 105, 110, 32, 104, 97, 110, 100, 46];

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PEridanAmpora begins the game with seven cards in hand.');
});

it('extracts wins-the-game text', function () {
    // From fixture line 6072
    $bytes = [53, 0, 0, 0, 3, 17, 186, 129, 196, 149, 91, 56, 103, 0, 0, 0, 0, 0, 0, 0, 29, 0, 0, 0,
        64, 80, 69, 114, 105, 100, 97, 110, 65, 109, 112, 111, 114, 97, 32, 119, 105, 110, 115, 32, 116, 104, 101, 32, 103, 97, 109, 101, 46];

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PEridanAmpora wins the game.');
});

it('extracts has-conceded text', function () {
    // From fixture line 6057
    $bytes = [64, 0, 0, 0, 3, 17, 186, 129, 196, 149, 91, 56, 103, 0, 0, 0, 0, 0, 0, 0, 40, 0, 0, 0,
        64, 80, 97, 110, 116, 105, 99, 108, 111, 115, 101, 114, 32, 104, 97, 115, 32, 99, 111, 110, 99, 101, 100, 101, 100, 32, 102, 114, 111, 109, 32, 116, 104, 101, 32, 103, 97, 109, 101, 46];

    expect(DecodeMetaMessageText::run($bytes))->toBe('@Panticloser has conceded from the game.');
});

it('returns null for state-update binary frame', function () {
    // A long type=44 state-update with no chat text. Real fragment, abbreviated.
    $bytes = [144, 7, 0, 0, 44, 18, 103, 197, 62, 7, 0, 0, 12, 0, 0, 0, 4, 0, 0, 0, 0, 0, 0, 0];

    expect(DecodeMetaMessageText::run($bytes))->toBeNull();
});

it('returns null for empty bytes', function () {
    expect(DecodeMetaMessageText::run([]))->toBeNull();
});

it('returns null for too-short bytes', function () {
    expect(DecodeMetaMessageText::run([1, 2, 3]))->toBeNull();
});

it('handles player names with digits and underscores', function () {
    // Synthesised: "@PCyber7777 rolled a 3."
    $text = '@PCyber7777 rolled a 3.';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    // Wrap with type=3 chat envelope: [len_u32, ...header(20 bytes), str_len_u32, ...textBytes]
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PCyber7777 rolled a 3.');
});

it('handles player names with dots', function () {
    // MTGO usernames may contain periods (e.g. "mr.moo"). A dotted
    // name must decode for every result phrase or the player's wins vanish.
    $texts = [
        '@Pmr.moo rolled a 4.',
        '@P@Pmr.moo joined the game.',
        '@Pmr.moo chooses to play first.',
        '@Pmr.moo begins the game with seven cards in hand.',
        '@Pmr.moo wins the game.',
        '@Pmr.moo has conceded from the game.',
        '@Pmr.moo wins the match 2-0',
    ];

    foreach ($texts as $text) {
        $textBytes = array_map('ord', str_split($text));
        $len = strlen($text);
        $bytes = array_merge(
            [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [$len, 0, 0, 0],
            $textBytes
        );

        expect(DecodeMetaMessageText::run($bytes))->toBe($text);
    }
});

it('extracts match score text', function () {
    $text = '@PEridanAmpora wins the match 2-0';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PEridanAmpora wins the match 2-0');
});

it('extracts disconnect text', function () {
    $text = '@PEridanAmpora has lost connection to the game.';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PEridanAmpora has lost connection to the game.');
});

it('extracts cast text with card markup', function () {
    $text = '@PEridanAmpora casts @[Guide of Souls@:251350,424:@]';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PEridanAmpora casts @[Guide of Souls@:251350,424:@]');
});

it('extracts plays text with card markup', function () {
    $text = '@PEridanAmpora plays @[Plains@:55294,423:@]';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PEridanAmpora plays @[Plains@:55294,423:@]');
});

it('extracts activates-ability text', function () {
    $text = '@PEridanAmpora activates an ability of @[Windswept Heath@:108404,428:@]';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PEridanAmpora activates an ability of @[Windswept Heath@:108404,428:@]');
});

it('extracts discards text', function () {
    $text = '@PPlayer discards @[Lightning Bolt@:1234,42:@]';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PPlayer discards @[Lightning Bolt@:1234,42:@]');
});

it('extracts turn marker', function () {
    $text = '@PTurn 5:';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PTurn 5:');
});

it('extracts mulligans text', function () {
    $text = '@PPlayer mulligans to six cards';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PPlayer mulligans to six cards');
});

it('extracts puts-onto-battlefield text', function () {
    $text = '@PPlayer puts @[Forest@:999,1:@] onto the battlefield';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PPlayer puts @[Forest@:999,1:@] onto the battlefield');
});

it('extracts opening-hand reveal text', function () {
    $text = '@PPlayer reveals @[Card@:111,2:@] from their opening hand';
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    expect(DecodeMetaMessageText::run($bytes))->toBe('@PPlayer reveals @[Card@:111,2:@] from their opening hand');
});
