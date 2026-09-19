<?php

declare(strict_types=1);

namespace App\Services\Sync\Bundles;

use App\Models\Draft;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Services\Sync\DeckClientId;
use App\Services\Sync\LeagueClientId;
use Illuminate\Support\Carbon;

/**
 * Builds the canonical league bundle and its metadata sidecar.
 *
 * leagues.token is a log session token reused across runs, not a league
 * identifier: the client_id is the composite (token, started_at) from
 * LeagueClientId, which is what keeps two leagues sharing a token distinct.
 */
class LeagueBundleBuilder
{
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
        return ['deckVersion.deck', 'draft.picks', 'deckSnapshots.match'];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(League $league): array
    {
        $league->loadMissing($this->relations());

        $deckVersion = $league->deckVersion;

        return [
            'league' => [
                'token' => $league->token,
                'name' => $league->name,
                'format' => $league->format,
                'started_at' => self::datetime($league->started_at),
                'joined_at' => self::datetime($league->joined_at),
                'dropped_at' => self::datetime($league->dropped_at),
                'completed_at' => self::datetime($league->completed_at),
                'state' => $league->state?->value,
                'notes' => $league->notes,
                'manual' => (bool) $league->manual,
                'deck_change_detected' => (bool) $league->deck_change_detected,
                'kind' => $league->kind?->value,
                'set_code' => $league->set_code,
                'mtgo_course_id' => $league->mtgo_course_id === null ? null : (int) $league->mtgo_course_id,
                'deck_mtgo_id' => $deckVersion?->deck ? (string) $deckVersion->deck->mtgo_id : null,
                'deck_version_signature' => $deckVersion?->signature,
            ],
            // A league has at most one draft today; carried as a list so a
            // future multi-draft shape is a data change, not a bundle shape
            // change.
            'drafts' => $league->draft === null ? [] : [$this->draftPayload($league->draft)],
            'snapshots' => $league->deckSnapshots
                ->sortBy(fn ($snapshot) => [self::datetime($snapshot->captured_at), (string) $snapshot->signature])
                ->values()
                ->map(fn ($snapshot) => $this->snapshotPayload($snapshot))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayload(Draft $draft): array
    {
        return [
            'draft_token' => $draft->draft_token,
            'mtgo_draft_id' => $draft->mtgo_draft_id === null ? null : (int) $draft->mtgo_draft_id,
            'pod_token' => $draft->pod_token,
            'seat_count' => $draft->seat_count === null ? null : (int) $draft->seat_count,
            'seat_index' => $draft->seat_index === null ? null : (int) $draft->seat_index,
            'booster_catalog_id' => $draft->booster_catalog_id === null ? null : (int) $draft->booster_catalog_id,
            'state' => $draft->state?->value,
            'pack_size' => $draft->pack_size === null ? null : (int) $draft->pack_size,
            'picks_expected' => $draft->picks_expected === null ? null : (int) $draft->picks_expected,
            'started_at' => self::datetime($draft->started_at),
            'ended_at' => self::datetime($draft->ended_at),
            'picks' => $draft->picks
                ->sortBy(fn ($pick) => (int) $pick->ordinal)
                ->values()
                ->map(fn ($pick) => [
                    'ordinal' => (int) $pick->ordinal,
                    'pack_number' => $pick->pack_number === null ? null : (int) $pick->pack_number,
                    'pick_number' => $pick->pick_number === null ? null : (int) $pick->pick_number,
                    'pack_id' => $pick->pack_id === null ? null : (int) $pick->pack_id,
                    'direction' => $pick->direction === null ? null : (int) $pick->direction,
                    'cards_available' => $pick->cards_available,
                    'picked_catalog_id' => $pick->picked_catalog_id === null ? null : (int) $pick->picked_catalog_id,
                    'picked_card_id' => $pick->picked_card_id === null ? null : (int) $pick->picked_card_id,
                    'reservations' => $pick->reservations,
                    'shown_at' => self::datetime($pick->shown_at),
                    'deadline_at' => self::datetime($pick->deadline_at),
                    'picked_at' => self::datetime($pick->picked_at),
                    'note' => $pick->note,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotPayload(LimitedDeckSnapshot $snapshot): array
    {
        return [
            'source' => $snapshot->source,
            'cards' => $snapshot->cards,
            'signature' => $snapshot->signature,
            'captured_at' => self::datetime($snapshot->captured_at),
            // The relation is the source of truth for locally recorded
            // rows; the stored column covers a snapshot imported before its
            // match arrived.
            'match_token' => $snapshot->match?->token ?? $snapshot->match_token,
        ];
    }

    public function clientId(League $league): string
    {
        return LeagueClientId::for($league);
    }

    /**
     * Every sidecar field is derived from bundle content, so a sidecar-only
     * change can never drift from what actually uploads.
     *
     * @return array<string, mixed>
     */
    public function sidecar(League $league): array
    {
        $bundle = $this->build($league);

        return array_filter([
            'occurred_at' => $bundle['league']['started_at'],
            'name' => $bundle['league']['name'],
            'format' => $bundle['league']['format'],
            'state' => $bundle['league']['state'],
            'deck_client_id' => $bundle['league']['deck_mtgo_id'] === null
                ? null
                : DeckClientId::for($bundle['league']['deck_mtgo_id']),
        ], fn (mixed $value) => $value !== null);
    }

    private static function datetime(?Carbon $value): ?string
    {
        return $value?->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
