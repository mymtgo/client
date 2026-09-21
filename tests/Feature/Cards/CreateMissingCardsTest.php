<?php

use App\Actions\Cards\CreateMissingCards;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('creates a stub for a plausible catalog id', function () {
    CreateMissingCards::run([126519]);

    expect(Card::where('mtgo_id', '126519')->exists())->toBeTrue();
});

it('refuses catalog ids far beyond anything MTGO has issued', function () {
    // Real catalog ids are five- to six-digit. Values like these have come
    // from misread data, and a stub for one can never resolve, so it sits in
    // the missing-card count forever.
    CreateMissingCards::run([89215000000, 590000000, 9496421, 126519]);

    expect(Card::pluck('mtgo_id')->all())->toBe(['126519']);
});

it('refuses ids that are not whole numbers', function () {
    CreateMissingCards::run(['', 'abc', null, -5, 126519]);

    expect(Card::pluck('mtgo_id')->all())->toBe(['126519']);
});

it('creates no duplicate when run twice with the same ids', function () {
    CreateMissingCards::run([126519]);
    CreateMissingCards::run([126519]);

    expect(Card::where('mtgo_id', '126519')->count())->toBe(1);
});

it('logs the ids it refused, so a source that feeds it non-card ids can be found', function () {
    Log::shouldReceive('channel')->with('pipeline')->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context) => str_contains($message, 'CreateMissingCards')
            && $context['rejected'] === [89215000000, 590000000]
    );

    CreateMissingCards::run([89215000000, 590000000, 126519]);
});

it('stays quiet when every id is plausible', function () {
    Log::shouldReceive('channel')->never();

    CreateMissingCards::run([126519]);
});
