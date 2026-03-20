<?php

use App\Actions\Util\ExtractJson;

it('extracts a single json object from text', function () {
    $text = 'some prefix {"key": "value"} some suffix';
    $result = ExtractJson::run($text);

    expect($result)->toHaveCount(1)
        ->and($result->first())->toBe(['key' => 'value']);
});

it('extracts multiple json objects', function () {
    $text = 'a {"a":1} b {"b":2} c';
    $result = ExtractJson::run($text);

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe(['a' => 1])
        ->and($result[1])->toBe(['b' => 2]);
});

it('uses fast path when entire text is valid json', function () {
    $text = '{"deck": [1, 2, 3]}';
    $result = ExtractJson::run($text);

    expect($result)->toHaveCount(1)
        ->and($result->first())->toBe(['deck' => [1, 2, 3]]);
});

it('extracts json arrays', function () {
    $text = 'prefix [1, 2, 3] suffix';
    $result = ExtractJson::run($text);

    expect($result)->toHaveCount(1)
        ->and($result->first())->toBe([1, 2, 3]);
});

it('returns empty collection for text with no json', function () {
    $result = ExtractJson::run('no json here');

    expect($result)->toBeEmpty();
});

it('does not time out on pathological input with many unmatched braces', function () {
    // 50k unmatched opening braces — would cause O(n²) scanning without the budget
    $text = str_repeat('{', 50_000);

    $start = microtime(true);
    $result = ExtractJson::run($text);
    $elapsed = microtime(true) - $start;

    expect($result)->toBeEmpty()
        ->and($elapsed)->toBeLessThan(5.0);
});
