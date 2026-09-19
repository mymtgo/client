<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\GetWidgetOptions;
use App\Actions\Dashboard\ResolveDashboardLayout;
use App\Dashboard\DashboardScope;
use App\Dashboard\WidgetType;
use App\Facades\AppSettings;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $timeframe = (string) $request->input('timeframe', 'alltime');
        $scope = DashboardScope::fromTimeframe($timeframe);
        $layout = ResolveDashboardLayout::run(AppSettings::dashboardLayout());

        $props = [
            'timeframe' => $timeframe,
            'hasMatches' => MtgoMatch::complete()
                ->when($scope->accountId, fn ($q, $id) => $q->forAccount($id))
                ->exists(),
            'layout' => array_map(fn (array $instance) => [
                'id' => $instance['id'],
                'type' => $instance['type'],
                'label' => $instance['type_instance']->label(),
                'span' => $instance['type_instance']->span(),
                'maxColumns' => $instance['type_instance']->maxColumns(),
                'config' => $instance['config'],
            ], $layout),
            'widgetOptions' => Inertia::defer(fn () => GetWidgetOptions::run($scope->accountId), 'options'),
        ];

        foreach ($layout as $instance) {
            $props['widget_'.$instance['id']] = Inertia::defer(
                fn () => $this->resolve($instance, $scope),
                $instance['id'],
            );
        }

        return Inertia::render('Index', $props);
    }

    /** @param array{config: array<string, mixed>, type_instance: WidgetType} $instance */
    private function resolve(array $instance, DashboardScope $scope): mixed
    {
        try {
            return $instance['type_instance']->resolve($instance['config'], $scope);
        } catch (Throwable $e) {
            report($e);

            return ['error' => true];
        }
    }
}
