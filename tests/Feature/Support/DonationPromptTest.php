<?php

use App\Facades\AppSettings;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('support.tix_handle', 'mymtgo_tix');
    config()->set('support.prompt_after_games', 50);
});

/**
 * Resolve the shared `donation` Inertia prop, invoking its lazy closure.
 *
 * @return array{showModal: bool, tixHandle: ?string}
 */
function donationProp(): array
{
    $shared = (new HandleInertiaRequests)->share(Request::create('/'));

    return value($shared['donation']);
}

/**
 * Track a number of games (all attached to a single match).
 */
function trackGames(int $count): void
{
    Game::factory()
        ->count($count)
        ->for(MtgoMatch::factory(), 'match')
        ->create();
}

it('shows the modal once enough games are tracked and the prompt is unseen', function () {
    trackGames(50);

    expect(donationProp()['showModal'])->toBeTrue();
});

it('does not show the modal below the game threshold', function () {
    trackGames(49);

    expect(donationProp()['showModal'])->toBeFalse();
});

it('does not show the modal once the prompt has been seen', function () {
    trackGames(60);
    AppSettings::setDonationPromptSeen(true);

    expect(donationProp()['showModal'])->toBeFalse();
});

it('does not show the modal when no tix handle is configured', function () {
    config()->set('support.tix_handle', null);
    trackGames(60);

    expect(donationProp()['showModal'])->toBeFalse();
});

it('honours a configurable game threshold', function () {
    config()->set('support.prompt_after_games', 10);
    trackGames(10);

    expect(donationProp()['showModal'])->toBeTrue();
});

it('shares the configured tix handle', function () {
    expect(donationProp()['tixHandle'])->toBe('mymtgo_tix');
});
