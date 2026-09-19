<?php

use App\Dashboard\DefaultLayout;
use App\Facades\AppSettings;

it('returns the default layout when nothing is stored', function () {
    expect(AppSettings::dashboardLayout())->toBe(DefaultLayout::instances())
        ->and(collect(DefaultLayout::instances())->pluck('type')->all())->toBe([
            'kpi_strip', 'league_results', 'rolling_form', 'last_session', 'deck_performance', 'matchup_spread', 'recent_matches',
        ]);
});

it('returns the default layout when the stored value is not a list', function () {
    AppSettings::set('dashboard_layout', 'garbage');

    expect(AppSettings::dashboardLayout())->toBe(DefaultLayout::instances());
});

it('round-trips a layout and is idempotent', function () {
    $layout = [
        ['id' => 'a', 'type' => 'kpi_strip', 'config' => []],
        ['id' => 'b', 'type' => 'deck_stats', 'config' => ['deck_id' => 3]],
    ];

    AppSettings::setDashboardLayout($layout);
    AppSettings::setDashboardLayout($layout);

    expect(AppSettings::dashboardLayout())->toBe($layout);
});
