<?php

namespace App\Http\Controllers\Limited;

use App\Actions\Limited\Read\BuildLimitedIndex;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request): Response
    {
        $timeframe = (string) $request->string('timeframe', 'alltime');
        $set = $request->string('set')->toString() ?: null;
        $kind = in_array($request->string('kind')->toString(), ['draft', 'sealed'], true)
            ? $request->string('kind')->toString()
            : null;
        [$from, $to] = $this->getTimeRange($timeframe);

        $index = BuildLimitedIndex::run($set, $kind, $from, $to);

        return Inertia::render('limited/Index', [
            'rows' => $index['rows'],
            'kpis' => $index['kpis'],
            'sets' => $index['sets'],
            'filters' => ['set' => $set, 'kind' => $kind, 'timeframe' => $timeframe],
        ]);
    }
}
