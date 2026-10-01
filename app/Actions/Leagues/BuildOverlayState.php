<?php

namespace App\Actions\Leagues;

use App\Actions\Decks\FindLastPlayedDeck;
use App\Enums\LeagueState;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Deck;
use App\Models\Game;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Support\ColorIdentity;
use App\Support\MtgoFormat;
use Illuminate\Support\Collection;

class BuildOverlayState
{
    private const WUBRG = ['W', 'U', 'B', 'R', 'G'];

    private const GRACE_MINUTES = 5;

    /**
     * The one payload every league overlay surface renders (window now, OBS
     * and hosted later). Leagues only; challenges arrive as another `event`
     * kind without reshaping anything else.
     *
     * @return array{
     *   status: 'idle'|'waiting'|'in_game'|'sideboarding'|'complete'|'trophied'|'dropped',
     *   event: array{kind: 'league', name: string, format: string}|null,
     *   record: array{wins: int, losses: int}|null,
     *   progress: array{played: int, of: int}|null,
     *   match: array{number: int, games: list<array{won: bool|null}>, gamesWon: int, gamesLost: int}|null,
     *   gameRecord: array{won: int, lost: int}|null,
     *   deck: array{id: int, name: string, archetype: string|null, colorIdentity: list<string>, label: string|null}|null,
     *   art: array{url: string}|null,
     *   size: 'full'|'compact',
     * }
     */
    public static function run(): array
    {
        $league = self::selectLeague();

        if (! $league) {
            $deck = FindLastPlayedDeck::run();

            return self::payload('idle', deck: $deck);
        }

        $matches = $league->matches()
            ->where('state', '!=', MatchState::Abandoned)
            ->orderBy('started_at')
            ->get(['id', 'mtgo_id', 'token', 'state', 'outcome', 'started_at', 'deck_version_id']);

        $deck = self::deckFor($league, $matches);
        $wins = $matches->where('state', MatchState::Complete)->where('outcome', MatchOutcome::Win)->count();
        $losses = $matches->where('state', MatchState::Complete)->where('outcome', MatchOutcome::Loss)->count();
        $roundCount = $league->kind->roundCount();

        $common = [
            'event' => ['kind' => 'league', 'name' => self::displayName((string) $league->name), 'format' => MtgoFormat::display($league->format)],
            'record' => ['wins' => $wins, 'losses' => $losses],
            'progress' => ['played' => $matches->where('state', MatchState::Complete)->count(), 'of' => $roundCount],
        ];

        if ($league->state === LeagueState::Dropped || $league->state === LeagueState::Complete) {
            $status = match (true) {
                $league->state === LeagueState::Dropped => 'dropped',
                $wins === $roundCount && $losses === 0 => 'trophied',
                default => 'complete',
            };

            return self::payload($status, deck: $deck, extra: $common + ['gameRecord' => self::gameRecord($matches)]);
        }

        // Newest wins: an older match stuck in progress must not take over the card.
        $active = $matches->last(fn (MtgoMatch $m) => in_array($m->state, [MatchState::Started, MatchState::InProgress], true));

        if (! $active) {
            return self::payload('waiting', deck: $deck, extra: $common);
        }

        /** @var Collection<int, Game> $games */
        $games = $active->games()->orderBy('started_at')->get(['id', 'won', 'started_at']);

        return self::payload(DecideMatchPhase::run($active, $games->count()), deck: $deck, extra: $common + [
            'match' => [
                'number' => $matches->search(fn (MtgoMatch $m) => $m->id === $active->id) + 1,
                'games' => $games->map(fn (Game $g) => ['won' => $g->won])->values()->all(),
                'gamesWon' => $games->whereStrict('won', true)->count(),
                'gamesLost' => $games->whereStrict('won', false)->count(),
            ],
        ]);
    }

    /**
     * Today's selection rules, unchanged: an Active league with matches
     * (preferring one with a live match, newest first), else a Complete or
     * Dropped one that ended inside the grace window.
     */
    private static function selectLeague(): ?League
    {
        $active = League::query()
            ->where('state', LeagueState::Active)
            ->has('matches')
            ->withCount(['matches as has_active_match_count' => fn ($q) => $q->whereIn('state', [MatchState::Started, MatchState::InProgress])])
            ->orderByDesc('has_active_match_count')
            ->latest('started_at')
            ->first();

        if ($active) {
            return $active;
        }

        $cutoff = now()->subMinutes(self::GRACE_MINUTES);

        return League::query()
            ->whereIn('state', [LeagueState::Complete, LeagueState::Dropped])
            ->has('matches')
            ->where(fn ($q) => $q->where('completed_at', '>=', $cutoff)->orWhere('dropped_at', '>=', $cutoff))
            ->latest('started_at')
            ->first();
    }

    /** @param Collection<int, MtgoMatch> $matches */
    private static function deckFor(League $league, Collection $matches): ?Deck
    {
        $league->loadMissing(['deckVersion.deck.cover', 'deckVersion.deck.archetype']);

        /** @var Deck|null $deck */
        $deck = $league->deckVersion?->deck;

        if ($deck) {
            return $deck;
        }

        $versioned = $matches->first(fn (MtgoMatch $m) => $m->deck_version_id !== null);

        /** @var Deck|null */
        return $versioned?->load(['deck.cover', 'deck.archetype'])->getRelation('deck');
    }

    /**
     * @param  Collection<int, MtgoMatch>  $matches
     * @return array{won: int, lost: int}
     */
    private static function gameRecord(Collection $matches): array
    {
        // Aliases avoid `won`: the model casts that column to boolean, which
        // would collapse a sum of 9 into true.
        $counts = Game::query()
            ->whereIn('match_id', $matches->pluck('id'))
            ->selectRaw('SUM(CASE WHEN won = 1 THEN 1 ELSE 0 END) AS games_won, SUM(CASE WHEN won = 0 THEN 1 ELSE 0 END) AS games_lost')
            ->toBase()
            ->first();

        return ['won' => (int) ($counts->games_won ?? 0), 'lost' => (int) ($counts->games_lost ?? 0)];
    }

    /**
     * MintLeague names a run "{structure} League d-m-Y h:mA". The stamp tells
     * a stream nothing, so drop it; a name the user typed is left alone.
     */
    private static function displayName(string $name): string
    {
        return preg_replace('/\s+\d{2}-\d{2}-\d{4} \d{2}:\d{2}[ap]m$/i', '', $name) ?? $name;
    }

    /**
     * The card's deck block. Public so the settings preview renders the real
     * last-played deck (and its label) through the same shape.
     *
     * @return array{id: int, name: string, archetype: string|null, colorIdentity: list<string>, label: string|null}|null
     */
    public static function deckPayload(?Deck $deck): ?array
    {
        if (! $deck) {
            return null;
        }

        return [
            'id' => $deck->id,
            'name' => (string) $deck->name,
            // What viewers recognise; the card prefers it over the MTGO deck name.
            'archetype' => $deck->archetype?->name,
            'colorIdentity' => self::colorIdentity($deck),
            'label' => AppSettings::overlayDeckLabels()[$deck->id] ?? null,
        ];
    }

    /** @return list<string> */
    private static function colorIdentity(?Deck $deck): array
    {
        $letters = explode(ColorIdentity::SEPARATOR, ColorIdentity::normalize($deck?->color_identity) ?? '');

        return array_values(array_filter(self::WUBRG, fn (string $c) => in_array($c, $letters, true)));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function payload(string $status, ?Deck $deck, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'event' => null,
            'record' => null,
            'progress' => null,
            'match' => null,
            'gameRecord' => null,
            'deck' => self::deckPayload($deck),
            'art' => ResolveOverlayArt::run($deck),
            'size' => AppSettings::overlaySize(),
        ], $extra);
    }
}
