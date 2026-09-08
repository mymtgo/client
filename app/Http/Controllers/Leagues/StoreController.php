<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\LeagueState;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leagues\StoreManualLeagueRequest;
use App\Models\Deck;
use App\Models\League;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function __invoke(StoreManualLeagueRequest $request): RedirectResponse
    {
        $deck = Deck::query()->findOrFail($request->integer('deck_id'));
        $latestVersion = $deck->latestVersion()->firstOrFail();

        // datetime-local input carries no zone: read it as system-local, store UTC.
        $startedAt = Carbon::parse($request->input('started_at'), AppSettings::systemTimezone())->utc();

        League::query()->create([
            'manual' => true,
            'token' => 'manual_'.Str::random(24),
            'name' => $request->string('name')->toString(),
            'format' => $deck->format,
            'state' => LeagueState::Complete,
            'started_at' => $startedAt,
            'joined_at' => $startedAt,
            'completed_at' => now(),
            'deck_version_id' => $latestVersion->id,
            'deck_change_detected' => false,
        ]);

        return redirect()->route('leagues.index');
    }
}
