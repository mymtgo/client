<?php

use App\Support\CanonicalJson;

it('is byte-stable regardless of key order and nesting', function () {
    $a = ['b' => 2, 'a' => ['y' => [3, 1], 'x' => 'ø/slash'], 'c' => null];
    $b = ['c' => null, 'a' => ['x' => 'ø/slash', 'y' => [3, 1]], 'b' => 2];

    expect(CanonicalJson::encode($a))->toBe(CanonicalJson::encode($b))
        ->and(CanonicalJson::hash($a))->toBe(CanonicalJson::hash($b));
});

it('does not reorder lists, only maps', function () {
    expect(CanonicalJson::encode(['k' => [2, 1]]))->toBe('{"k":[2,1]}');
});

it('emits no whitespace and leaves slashes and unicode unescaped', function () {
    expect(CanonicalJson::encode(['url' => 'a/b', 'name' => 'ø']))
        ->toBe('{"name":"ø","url":"a/b"}');
});

it('hashes to lowercase sha256 hex of the encoded bytes', function () {
    $data = ['token' => 'abc'];

    expect(CanonicalJson::hash($data))
        ->toBe(hash('sha256', CanonicalJson::encode($data)));
});
