<?php

use App\Dashboard\WidgetRegistry;
use App\Dashboard\WidgetType;

it('registers the kpi strip widget', function () {
    $registry = app(WidgetRegistry::class);

    expect($registry->has('kpi_strip'))->toBeTrue()
        ->and($registry->find('kpi_strip'))->toBeInstanceOf(WidgetType::class)
        ->and($registry->find('kpi_strip')->span())->toBe(12)
        ->and($registry->find('kpi_strip')->allowsMultiple())->toBeFalse()
        ->and($registry->find('nope'))->toBeNull();
});

it('keys every widget by its own key', function () {
    foreach (app(WidgetRegistry::class)->all() as $key => $type) {
        expect($type->key())->toBe($key)
            ->and($type->span())->toBeIn([3, 4, 6, 12])
            ->and($type->maxColumns())->toBeGreaterThanOrEqual($type->span())
            ->and($type->maxColumns())->toBeLessThanOrEqual(12);
    }
});

it('registers every existing dashboard card with its span', function (string $key, int $span) {
    expect(app(WidgetRegistry::class)->find($key)?->span())->toBe($span);
})->with([
    ['league_results', 4],
    ['rolling_form', 4],
    ['last_session', 4],
    ['deck_performance', 6],
    ['matchup_spread', 6],
    ['recent_matches', 12],
]);
