<?php

use App\Support\MtgoFormat;

it('humanises a raw MTGO format code', function (string $code, string $display) {
    expect(MtgoFormat::display($code))->toBe($display);
})->with([
    ['CMODERN', 'Modern'],
    ['CSTANDARD', 'Standard'],
    ['CPIONEER', 'Pioneer'],
    ['CPAUPER', 'Pauper'],
    ['CLEGACY', 'Legacy'],
    ['CVINTAGE', 'Vintage'],
    ['CPREMODERN', 'Premodern'],
    ['CModern', 'Modern'],
]);

it('leaves an already humanised label alone', function () {
    expect(MtgoFormat::display('Modern'))->toBe('Modern')
        ->and(MtgoFormat::display('standard'))->toBe('Standard')
        ->and(MtgoFormat::display('Limited'))->toBe('Limited');
});

it('does not strip a leading C from a real format name', function () {
    expect(MtgoFormat::display('Commander'))->toBe('Commander')
        ->and(MtgoFormat::key('Commander'))->toBe('commander');
});

it('gives the archetypes format key for a code or a label', function (string $input, string $key) {
    expect(MtgoFormat::key($input))->toBe($key);
})->with([
    ['CSTANDARD', 'standard'],
    ['CPIONEER', 'pioneer'],
    ['CMODERN', 'modern'],
    ['Modern', 'modern'],
    ['standard', 'standard'],
]);

it('returns an empty string for a missing format', function () {
    expect(MtgoFormat::display(null))->toBe('')
        ->and(MtgoFormat::display(''))->toBe('')
        ->and(MtgoFormat::key(null))->toBe('')
        ->and(MtgoFormat::key(''))->toBe('');
});
