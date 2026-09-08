<?php

use App\Support\ColorIdentity;

it('normalises concatenated and comma separated identities to the same canonical form', function (?string $input, ?string $expected) {
    expect(ColorIdentity::normalize($input))->toBe($expected);
})->with([
    'concatenated' => ['UB', 'U,B'],
    'comma separated' => ['U,B', 'U,B'],
    'spaces and lowercase' => [' u, b ', 'U,B'],
    'five colour' => ['WUBRG', 'W,U,B,R,G'],
    'mono' => ['G', 'G'],
    'colorless' => ['C', 'C'],
    'duplicates collapse' => ['UUB', 'U,B'],
    'unknown letters dropped' => ['UXB', 'U,B'],
    'empty string' => ['', null],
    'null' => [null, null],
]);
