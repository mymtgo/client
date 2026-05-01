<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\LeagueState;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class OverlayController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $baseQuery = fn () => League::withCount([
            'matches as wins_count' => fn ($q) => $q->where('state', MatchState::Complete)->where('outcome', 'win'),
            'matches as losses_count' => fn ($q) => $q->where('state', MatchState::Complete)->where('outcome', 'loss'),
            'matches as total_matches_count',
            'matches as has_active_match_count' => fn ($q) => $q->whereIn('state', [MatchState::Started, MatchState::InProgress]),
        ])
            ->with(['deckVersion.deck.cover'])
            ->has('matches');

        $league = $baseQuery()
            ->where('leagues.state', LeagueState::Active)
            ->orderByDesc('has_active_match_count')
            ->latest('started_at')
            ->first();

        if (! $league) {
            $league = $baseQuery()
                ->whereHas('matches', fn ($q) => $q->where('matches.created_at', '>=', now()->subMinutes(5)))
                ->latest('started_at')
                ->first();
        }

        if (! $league) {
            return Inertia::render('leagues/Overlay', [
                'league' => null,
            ]);
        }

        $currentMatch = $league->matches()
            ->whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->first();

        $games = $currentMatch
            ? $currentMatch->games()->orderBy('started_at')->get()->map(fn ($game) => [
                'won' => $game->won,
                'ended' => $game->ended_at !== null,
            ])->values()->all()
            : [];

        /** @var Deck|null $deckModel */
        $deckModel = $league->deckVersion?->deck;
        if (! $deckModel) {
            /** @var Deck|null $deckModel */
            $deckModel = $league->matches()
                ->whereNotNull('deck_version_id')
                ->with(['deck.cover', 'deck.archetype'])
                ->first()
                ?->getRelation('deck');
        }
        $deckName = $deckModel?->name;

        $customBackgroundPath = AppSettings::overlayBackgroundPath();
        $overlayDisk = Storage::disk('overlay');
        $backgroundUrl = $customBackgroundPath && $overlayDisk->exists($customBackgroundPath)
            ? $overlayDisk->url($customBackgroundPath)
            : $deckModel?->cover?->art_crop_url;

        return Inertia::render('leagues/Overlay', [
            'league' => [
                'id' => $league->id,
                'name' => $league->name,
                'format' => MtgoMatch::displayFormat($league->format),
                'wins' => $league->wins_count,
                'losses' => $league->losses_count,
                'totalMatches' => $league->total_matches_count,
                'deckId' => $deckModel?->id,
                'deckName' => $deckName,
                'backgroundUrl' => $backgroundUrl,
                'hasActiveMatch' => ! is_null($currentMatch),
                'games' => $games,
            ],
        ]);
    }
}
