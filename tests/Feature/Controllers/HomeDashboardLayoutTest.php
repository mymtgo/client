<?php

use App\Dashboard\DashboardScope;
use App\Dashboard\WidgetRegistry;
use App\Dashboard\WidgetType;
use App\Facades\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the default layout with one deferred prop per instance', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('hasMatches', false)
            ->where('timeframe', 'alltime')
            ->has('layout', 7)
            ->where('layout.0', ['id' => 'default-kpi-strip', 'type' => 'kpi_strip', 'label' => 'Overview', 'span' => 12, 'maxColumns' => 12, 'config' => []])
            ->missing('widget_default-kpi-strip')
            ->missing('formats')
        );
});

it('only emits props for widgets in the stored layout', function () {
    AppSettings::setDashboardLayout([
        ['id' => 'only', 'type' => 'rolling_form', 'config' => []],
    ]);

    inertiaPartial(route('home'), 'Index', ['widget_only', 'widget_default-kpi-strip'])
        ->assertOk()
        ->assertJsonPath('props.widget_only.results', [])
        ->assertJsonMissingPath('props.widget_default-kpi-strip');
});

it('ignores the old format query param', function () {
    $this->get(route('home', ['format' => 'CStandard']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('format'));
});

it('resolves a throwing widget to an error marker instead of failing the page', function () {
    $throwing = new class implements WidgetType
    {
        public function key(): string
        {
            return 'boom';
        }

        public function label(): string
        {
            return 'Boom';
        }

        public function span(): int
        {
            return 2;
        }

        public function maxColumns(): int
        {
            return 6;
        }

        public function allowsMultiple(): bool
        {
            return false;
        }

        public function defaultConfig(): array
        {
            return [];
        }

        public function validateConfig(array $config): array
        {
            return [];
        }

        public function resolve(array $config, DashboardScope $scope): mixed
        {
            throw new RuntimeException('boom');
        }
    };
    app()->instance(WidgetRegistry::class, new WidgetRegistry([$throwing]));
    AppSettings::setDashboardLayout([['id' => 'b', 'type' => 'boom', 'config' => []]]);

    inertiaPartial(route('home'), 'Index', ['widget_b'])
        ->assertOk()
        ->assertJsonPath('props.widget_b.error', true);
});
