<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Leagues\FormatLeagueRuns;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\League;
use Inertia\Inertia;
use Inertia\Response;
use Native\Desktop\Facades\Settings;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $hidePhantom = (bool) Settings::get('hide_phantom_leagues');
        $activeAccountId = Account::active()->value('id');

        $leagues = League::query()
            ->when($hidePhantom, fn ($q) => $q->where('phantom', false))
            ->whereHas('matches', fn ($q) => $q->where('state', 'complete'))
            ->with(['deckVersion.deck'])
            ->orderByDesc('started_at')
            ->get();

        return Inertia::render('leagues/Index', [
            'leagues' => FormatLeagueRuns::run($leagues, $activeAccountId),
            'hidePhantomLeagues' => $hidePhantom,
        ]);
    }
}
