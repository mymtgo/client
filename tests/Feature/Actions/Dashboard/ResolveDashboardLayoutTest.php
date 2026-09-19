<?php

use App\Actions\Dashboard\ResolveDashboardLayout;
use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\WidgetRegistry;
use App\Dashboard\Widgets\KpiStripWidget;
use App\Dashboard\WidgetType;
use App\Models\Account;
use App\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $deckStats = new class implements WidgetType
    {
        public function key(): string
        {
            return 'deck_stats';
        }

        public function label(): string
        {
            return 'Deck stats';
        }

        public function span(): int
        {
            return 3;
        }

        public function maxColumns(): int
        {
            return 6;
        }

        public function allowsMultiple(): bool
        {
            return true;
        }

        public function defaultConfig(): array
        {
            return ['deck_id' => null];
        }

        public function validateConfig(array $config): array
        {
            if (! isset($config['deck_id']) || ! is_int($config['deck_id'])) {
                throw new InvalidWidgetConfig('deck_id required');
            }

            return ['deck_id' => $config['deck_id']];
        }

        public function resolve(array $config, DashboardScope $scope): mixed
        {
            return null;
        }
    };

    app()->instance(WidgetRegistry::class, new WidgetRegistry([new KpiStripWidget, $deckStats]));
});

it('drops unknown types, bad config and duplicate single types, keeping order', function () {
    $account = Account::create(['username' => 'me', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);

    $resolved = ResolveDashboardLayout::run([
        ['id' => 'b', 'type' => 'deck_stats', 'config' => ['deck_id' => $deck->id]],
        ['id' => 'x', 'type' => 'nope', 'config' => []],
        ['id' => 'a', 'type' => 'kpi_strip', 'config' => []],
        ['id' => 'a2', 'type' => 'kpi_strip', 'config' => []],
        ['id' => 'c', 'type' => 'deck_stats', 'config' => ['deck_id' => 'not-an-int']],
        ['id' => 'd', 'type' => 'deck_stats', 'config' => ['deck_id' => $deck->id]],
    ]);

    expect(collect($resolved)->pluck('id')->all())->toBe(['b', 'a', 'd'])
        ->and($resolved[0]['type_instance'])->toBeInstanceOf(WidgetType::class);
});

it('drops deck stats for missing, trashed and other-account decks', function () {
    $mine = Account::create(['username' => 'me', 'active' => true, 'tracked' => true]);
    $other = Account::create(['username' => 'other', 'active' => false, 'tracked' => true]);
    $myDeck = Deck::factory()->create(['account_id' => $mine->id]);
    $trashed = Deck::factory()->create(['account_id' => $mine->id]);
    $trashed->delete();
    $theirs = Deck::factory()->create(['account_id' => $other->id]);

    $resolved = ResolveDashboardLayout::run([
        ['id' => 'ok', 'type' => 'deck_stats', 'config' => ['deck_id' => $myDeck->id]],
        ['id' => 'gone', 'type' => 'deck_stats', 'config' => ['deck_id' => 999999]],
        ['id' => 'trashed', 'type' => 'deck_stats', 'config' => ['deck_id' => $trashed->id]],
        ['id' => 'theirs', 'type' => 'deck_stats', 'config' => ['deck_id' => $theirs->id]],
    ]);

    expect(collect($resolved)->pluck('id')->all())->toBe(['ok']);
});

it('ignores rows that are not shaped like an instance', function () {
    $resolved = ResolveDashboardLayout::run([
        'string',
        ['type' => 'kpi_strip'],
        ['id' => '', 'type' => 'kpi_strip', 'config' => []],
        ['id' => 'a', 'type' => 'kpi_strip'],
    ]);

    expect(collect($resolved)->pluck('id')->all())->toBe(['a']);
});
