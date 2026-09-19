<?php

declare(strict_types=1);

namespace App\Services\Sync\Bundles;

use App\Models\CardGameStat;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Services\Sync\DeckClientId;
use App\Services\Sync\LeagueClientId;
use App\Support\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the canonical match bundle and its metadata sidecar.
 *
 * Every measurement column on card_game_stats is hard-coded below rather
 * than read from the schema at runtime: the bundle format must not change
 * shape when a migration lands without a canonical version bump.
 *
 * @see CardGameStat
 */
class MatchBundleBuilder
{
    /**
     * card_game_stats measurement columns: every column of card_game_stats
     * except id, game_id, deck_version_id, oracle_id, created_at, updated_at
     * (oracle_id is carried separately, as a key rather than a measurement).
     * The value is how the raw column is coerced for the bundle.
     *
     * @var array<string, 'int'|'bool'>
     */
    private const MEASUREMENT_COLUMNS = [
        'quantity' => 'int',
        'kept' => 'int',
        'seen' => 'int',
        'won' => 'bool',
        'is_postboard' => 'bool',
        'sided_out' => 'bool',
        'cast' => 'int',
        'sided_in' => 'bool',
        'played' => 'int',
        'kicked' => 'int',
        'flashback' => 'int',
        'madness' => 'int',
        'evoked' => 'int',
        'activated' => 'int',
        'pregame_revealed' => 'bool',
        'pregame_played' => 'bool',
        'opponent' => 'bool',
        'warp' => 'int',
        'free_cast' => 'int',
        'bargained' => 'int',
        'dashed' => 'int',
        'bestowed' => 'int',
        'replicated' => 'int',
        'spectacle' => 'int',
        'rebound' => 'int',
        'escaped' => 'int',
        'ninjutsu' => 'int',
        'suspended' => 'int',
        'buyback' => 'int',
        'disturb' => 'int',
        'foretold' => 'int',
        'retraced' => 'int',
        'mayhem' => 'int',
        'miracle' => 'int',
        'gifted' => 'int',
        'casualty' => 'int',
    ];

    /**
     * The relations the builder reads. Loaded with loadMissing so a caller
     * that already eager-loaded (via {@see relations()}, on the query
     * SyncRunner batches with) pays no extra query.
     *
     * @return array<int, string>
     */
    private const RELATIONS = [
        'games.timeline',
        'games.players',
        'games.cardGameStats',
        'archetypes.archetype',
        'archetypes.player',
        'deckVersion.deck',
        'league',
    ];

    /**
     * The dot-notation relation paths this builder reads, for a caller
     * (SyncRunner) to eager-load with ->with() ahead of a batch of builds,
     * instead of paying loadMissing's per-row query cost across a whole
     * lazyById scan.
     *
     * @return array<int, string>
     */
    public function relations(): array
    {
        return self::RELATIONS;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(MtgoMatch $match): array
    {
        $match->loadMissing(self::RELATIONS);

        // Deterministic child ordering is what makes two devices building
        // the same match produce byte-identical bundles: games.mtgo_id has
        // duplicates globally but not within one match, so it is a safe
        // key inside this bundle.
        $games = $match->games->sortBy('mtgo_id')->values();

        return [
            'match' => $this->matchPayload($match),
            'games' => $games->map(fn (Game $game) => $this->gamePayload($game))->all(),
            'players' => $this->playersPayload($games),
            'timelines' => $this->timelinesPayload($games),
            'card_stats' => $this->cardStatsPayload($games),
            'archetypes' => $this->archetypesPayload($match),
        ];
    }

    public function clientId(MtgoMatch $match): string
    {
        return $match->token;
    }

    /**
     * Every sidecar field is derived from bundle content, so a sidecar-only
     * change can never drift from what actually uploads: a clean row never
     * uploads, so the two must move together.
     *
     * @return array<string, mixed>
     */
    public function sidecar(MtgoMatch $match): array
    {
        $bundle = $this->build($match);

        return array_filter([
            'occurred_at' => $bundle['match']['started_at'],
            'format' => $bundle['match']['format'],
            'match_type' => $bundle['match']['match_type'],
            'result' => $bundle['match']['result'],
            'outcome' => $bundle['match']['outcome'],
            'games_won' => $bundle['match']['games_won'],
            'games_lost' => $bundle['match']['games_lost'],
            // Sanitized exactly like DeckBundleBuilder::clientId so the
            // sidecar reference matches the id the deck actually uploads
            // under (and passes the server's client-id rule).
            'deck_client_id' => $bundle['match']['deck_mtgo_id'] === null
                ? null
                : DeckClientId::for($bundle['match']['deck_mtgo_id']),
            'archetype_uuid' => $this->sideArchetypeUuid($match, opponent: false),
            'opponent_archetype_uuid' => $this->sideArchetypeUuid($match, opponent: true),
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function matchPayload(MtgoMatch $match): array
    {
        $deckVersion = $match->deckVersion;

        return [
            'token' => $match->token,
            'mtgo_id' => $match->mtgo_id,
            'format' => $match->format,
            'match_type' => $match->match_type,
            'result' => $match->result,
            'outcome' => $match->outcome?->value,
            'games_won' => (int) $match->games_won,
            'games_lost' => (int) $match->games_lost,
            'started_at' => self::datetime($match->started_at),
            'ended_at' => self::datetime($match->ended_at),
            'state' => $match->state?->value,
            'notes' => $match->notes,
            'imported' => (bool) $match->imported,
            'manual' => (bool) $match->manual,
            'deck_mtgo_id' => $deckVersion?->deck ? (string) $deckVersion->deck->mtgo_id : null,
            'deck_version_signature' => $deckVersion?->signature,
            'league_client_id' => $match->league ? LeagueClientId::for($match->league) : null,
            'tournament_mtgo_event_id' => $match->tournament_event_id === null ? null : (int) $match->tournament_event_id,
            'tournament_round' => $match->tournament_round === null ? null : (int) $match->tournament_round,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gamePayload(Game $game): array
    {
        return [
            'mtgo_id' => $game->mtgo_id,
            'won' => $game->won === null ? null : (bool) $game->won,
            'started_at' => self::datetime($game->started_at),
            'ended_at' => self::datetime($game->ended_at),
            'turn_count' => $game->turn_count === null ? null : (int) $game->turn_count,
        ];
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<int, array<string, mixed>>
     */
    private function playersPayload(Collection $games): array
    {
        return $games->flatMap(function (Game $game) {
            return $game->players
                ->sortBy('username')
                ->values()
                ->map(fn ($player) => [
                    'game' => $game->mtgo_id,
                    'username' => $player->username,
                    'is_local' => (bool) $player->pivot->is_local,
                    'on_play' => (bool) $player->pivot->on_play,
                    'starting_hand_size' => $player->pivot->starting_hand_size === null ? null : (int) $player->pivot->starting_hand_size,
                    'mulligan_count' => $player->pivot->mulligan_count === null ? null : (int) $player->pivot->mulligan_count,
                    'dice_roll' => $player->pivot->dice_roll === null ? null : (int) $player->pivot->dice_roll,
                    'deck_json' => $player->pivot->deck_json,
                    // Hand-entered opening hands (manual matches) live on
                    // the pivot rather than in a timeline, so the pivot is
                    // the only place they can travel from.
                    'opening_hand_json' => $player->pivot->opening_hand_json,
                ]);
        })->all();
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<int, array<string, mixed>>
     */
    private function timelinesPayload(Collection $games): array
    {
        return $games->flatMap(function (Game $game) {
            // game_timelines.timestamp is not cast to a datetime on the
            // model, so it arrives as a raw driver string; parse it once
            // up front, for both the sort key and the emitted value,
            // rather than repeatedly inside the sort comparator.
            $rows = $game->timeline->map(fn ($timeline) => [
                'timestamp' => Carbon::parse($timeline->timestamp),
                'content' => $timeline->content,
            ])->all();

            // timestamp is only second precision, so same-second rows are
            // common (rapid log events). The tiebreak must be content
            // derived, never the local autoincrement row id: two devices
            // insert rows in different orders, so an id tiebreak would not
            // be byte-stable across devices.
            usort($rows, fn (array $a, array $b) => [$a['timestamp']->getTimestamp(), self::contentHash($a['content'])]
                <=> [$b['timestamp']->getTimestamp(), self::contentHash($b['content'])]);

            return collect($rows)->map(fn (array $row) => [
                'game' => $game->mtgo_id,
                'timestamp' => self::datetime($row['timestamp']),
                'content' => $row['content'],
            ]);
        })->all();
    }

    /**
     * @param  mixed  $content
     */
    private static function contentHash($content): string
    {
        return hash('sha256', CanonicalJson::encode(is_array($content) ? $content : []));
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<int, array<string, mixed>>
     */
    private function cardStatsPayload(Collection $games): array
    {
        $rows = $games->flatMap(fn (Game $game) => $game->cardGameStats->map(fn ($stat) => [$game, $stat]));

        $rows = $rows->sort(function (array $a, array $b) {
            [$gameA, $statA] = $a;
            [$gameB, $statB] = $b;

            return [$gameA->mtgo_id, $statA->oracle_id, (int) $statA->is_postboard, (int) $statA->opponent]
                <=> [$gameB->mtgo_id, $statB->oracle_id, (int) $statB->is_postboard, (int) $statB->opponent];
        })->values();

        return $rows->map(function (array $pair) {
            [$game, $stat] = $pair;

            $payload = [
                'game' => $game->mtgo_id,
                'oracle_id' => $stat->oracle_id,
            ];

            foreach (self::MEASUREMENT_COLUMNS as $column => $type) {
                $value = $stat->{$column};

                $payload[$column] = match (true) {
                    $value === null => null,
                    $type === 'bool' => (bool) $value,
                    default => (int) $value,
                };
            }

            return $payload;
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function archetypesPayload(MtgoMatch $match): array
    {
        // uuid alone ties on a mirror match (both sides playing the same
        // archetype), so player_username breaks the tie.
        return $match->archetypes
            ->sort(fn (MatchArchetype $a, MatchArchetype $b) => [$a->archetype?->uuid, $a->player?->username]
                <=> [$b->archetype?->uuid, $b->player?->username])
            ->values()
            ->map(fn (MatchArchetype $row) => [
                'uuid' => $row->archetype?->uuid,
                'confidence' => number_format((float) $row->confidence, 2, '.', ''),
                'manual' => (bool) $row->manual,
                'player_username' => $row->player?->username,
                'player_is_player' => (bool) ($row->player?->is_player ?? false),
            ])->all();
    }

    /**
     * The archetype uuid for one side of the match, mirroring
     * SubmitMatchToApi's side logic: the opponent side is whichever
     * archetype's player_id shows up as an opponent (is_local false) on
     * this match's games. The player side is whoever is not.
     */
    private function sideArchetypeUuid(MtgoMatch $match, bool $opponent): ?string
    {
        $opponentPlayerIds = $match->games
            ->flatMap(fn (Game $game) => $game->players->filter(fn ($player) => $player->pivot->is_local === false))
            ->pluck('id')
            ->unique();

        $row = $match->archetypes->first(
            fn (MatchArchetype $row) => $opponentPlayerIds->contains($row->player_id) === $opponent
        );

        return $row?->archetype?->uuid;
    }

    private static function datetime(?Carbon $value): ?string
    {
        return $value?->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
