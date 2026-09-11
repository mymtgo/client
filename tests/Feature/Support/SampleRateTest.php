<?php

use App\Support\SampleRate;

it('reports a whole-number percentage of the games behind it', function () {
    expect(SampleRate::of(60, 80)->percentage())->toBe(75);
});

it('rounds to the nearest whole percent', function () {
    expect(SampleRate::of(1, 3)->percentage())->toBe(33)
        ->and(SampleRate::of(2, 3)->percentage())->toBe(67);
});

it('has no percentage without a sample, which is not the same as zero percent', function () {
    expect(SampleRate::of(0, 0)->percentage())->toBeNull()
        ->and(SampleRate::empty()->percentage())->toBeNull();
});

it('treats missing counts as no sample at all', function () {
    expect(SampleRate::of(null, null)->percentage())->toBeNull()
        ->and(SampleRate::of(5, null)->percentage())->toBeNull();
});

it('never reports negative counts', function () {
    $rate = SampleRate::of(-5, -10);

    expect($rate->count)->toBe(0)
        ->and($rate->games)->toBe(0);
});

it('is confident once the sample reaches the floor', function () {
    expect(SampleRate::of(5, 10)->isConfident(10))->toBeTrue()
        ->and(SampleRate::of(5, 9)->isConfident(10))->toBeFalse();
});

it('is never confident without a sample', function () {
    expect(SampleRate::empty()->isConfident(0))->toBeFalse();
});

it('supports a call at the share, but only on a confident sample', function () {
    expect(SampleRate::of(50, 100)->supports(50, 10))->toBeTrue()
        ->and(SampleRate::of(49, 100)->supports(50, 10))->toBeFalse();
});

it('withholds a call when the share is met on too small a sample', function () {
    expect(SampleRate::of(1, 1)->supports(50, 10))->toBeFalse();
});

it('gives the same answer for the same input', function () {
    $first = SampleRate::of(60, 80);
    $second = SampleRate::of(60, 80);

    expect($first->percentage())->toBe($second->percentage())
        ->and($first->supports(50, 10))->toBe($second->supports(50, 10));
});
