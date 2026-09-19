<?php

use App\Enums\LeagueKind;
use App\Facades\AppSettings;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('saves a valid layout and redirects back', function () {
    $this->from(route('home'))->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'rolling_form', 'config' => []],
        ['id' => 'b', 'type' => 'kpi_strip', 'config' => []],
    ]])->assertRedirect(route('home'));

    expect(collect(AppSettings::dashboardLayout())->pluck('type')->all())->toBe(['rolling_form', 'kpi_strip']);
});

it('rejects unknown types, duplicate ids, duplicate single types and oversize layouts', function (array $layout, string $errorKey) {
    $this->post(route('dashboard.layout'), ['layout' => $layout])->assertSessionHasErrors($errorKey);
})->with([
    'unknown type' => [[['id' => 'a', 'type' => 'nope', 'config' => []]], 'layout.0.type'],
    'duplicate id' => [[['id' => 'a', 'type' => 'kpi_strip', 'config' => []], ['id' => 'a', 'type' => 'rolling_form', 'config' => []]], 'layout.1.id'],
    'duplicate single' => [[['id' => 'a', 'type' => 'kpi_strip', 'config' => []], ['id' => 'b', 'type' => 'kpi_strip', 'config' => []]], 'layout.1.type'],
    'too many' => [array_map(fn ($i) => ['id' => "w$i", 'type' => 'rolling_form', 'config' => []], range(1, 31)), 'layout'],
]);

it('accepts an empty layout', function () {
    $this->post(route('dashboard.layout'), ['layout' => []])->assertSessionHasNoErrors();

    expect(AppSettings::dashboardLayout())->toBe([]);
});

it('rejects a deck stats widget for a deck the active account cannot see', function () {
    $mine = Account::create(['username' => 'me', 'active' => true, 'tracked' => true]);
    $other = Account::create(['username' => 'other', 'active' => false, 'tracked' => true]);
    $theirs = Deck::factory()->create(['account_id' => $other->id]);
    $myDeck = Deck::factory()->create(['account_id' => $mine->id]);

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'deck_stats', 'config' => ['deck_id' => $theirs->id]],
    ]])->assertSessionHasErrors('layout.0.config.deck_id');

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'deck_stats', 'config' => ['deck_id' => $myDeck->id]],
        ['id' => 'b', 'type' => 'deck_stats', 'config' => ['deck_id' => $myDeck->id]],
    ]])->assertSessionHasNoErrors();
});

it('rejects an unknown limited set on the picks widget', function () {
    League::factory()->complete()->create(['kind' => LeagueKind::Draft, 'set_code' => 'NEW']);

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'limited_picks', 'config' => ['set_code' => 'ZZZ']],
    ]])->assertSessionHasErrors('layout.0.config.set_code');

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'limited_picks', 'config' => ['set_code' => 'NEW']],
        ['id' => 'b', 'type' => 'limited_league', 'config' => []],
    ]])->assertSessionHasNoErrors();
});

it('rejects unknown or limited format codes on the format widget', function () {
    MtgoMatch::factory()->create(['format' => 'CModern']);
    MtgoMatch::factory()->create(['format' => 'DHOBHOBHOB']);

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'format_stats', 'config' => ['formats' => ['CLegacy']]],
    ]])->assertSessionHasErrors('layout.0.config.formats');

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'format_stats', 'config' => ['formats' => ['DHOBHOBHOB']]],
    ]])->assertSessionHasErrors('layout.0.config.formats');

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'format_stats', 'config' => ['formats' => ['CModern']]],
    ]])->assertSessionHasNoErrors();
});

it('rejects an unknown format on the league results widget', function () {
    MtgoMatch::factory()->create(['format' => 'CModern']);

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'league_results', 'config' => ['format' => 'CLegacy']],
    ]])->assertSessionHasErrors('layout.0.config.format');

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'league_results', 'config' => ['format' => 'CModern']],
    ]])->assertSessionHasNoErrors();
});

it('rejects an archetype stats widget for an archetype none of my decks use', function () {
    $mine = Account::create(['username' => 'me', 'active' => true, 'tracked' => true]);
    $used = Archetype::factory()->create();
    $unused = Archetype::factory()->create();
    Deck::factory()->create(['account_id' => $mine->id, 'archetype_id' => $used->id]);

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'archetype_stats', 'config' => ['archetype_id' => $unused->id]],
    ]])->assertSessionHasErrors('layout.0.config.archetype_id');

    $this->post(route('dashboard.layout'), ['layout' => [
        ['id' => 'a', 'type' => 'archetype_stats', 'config' => ['archetype_id' => $used->id]],
        ['id' => 'b', 'type' => 'archetype_stats', 'config' => ['archetype_id' => $used->id]],
    ]])->assertSessionHasNoErrors();
});
