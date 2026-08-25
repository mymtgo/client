<?php

namespace App\Actions\Drafts;

use App\Actions\Cards\CreateMissingCards;
use App\Actions\Leagues\ResolveLeagueSetCode;
use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Actions\Util\RepairJson;
use App\Enums\DraftState;
use App\Enums\LogEventType;
use App\Events\DraftEnded;
use App\Events\DraftPickCommitted;
use App\Events\DraftPickPending;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\LogEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ApplyDraftEvent
{
    /**
     * Apply one classified draft LogEvent to the drafts / draft_picks tables.
     * Every branch upserts on a natural key so replay is safe.
     */
    public static function run(LogEvent $event): void
    {
        match ($event->event_type) {
            LogEventType::DRAFT_CREATED->value => self::created($event),
            LogEventType::DRAFT_LEAGUE_STANDING->value => self::standing($event),
            LogEventType::DRAFT_JOINED->value => self::joined($event),
            LogEventType::DRAFT_POD_STATE->value => self::podState($event),
            LogEventType::DRAFT_PACK_OPENED->value => null,
            LogEventType::DRAFT_PENDING_PICK->value => self::pendingPick($event),
            LogEventType::DRAFT_SELECTION->value => self::selection($event),
            LogEventType::DRAFT_PICK_COMMITTED->value => self::committed($event),
            LogEventType::DRAFT_ENDED->value => self::ended($event),
            LogEventType::DRAFT_STATE_CHANGED->value => self::stateChanged($event),
            LogEventType::LEAGUE_POOL_GRANTED->value => self::poolGranted($event),
            default => null,
        };
    }

    private static function draftFor(LogEvent $event): ?Draft
    {
        if (! $event->draft_token) {
            return null;
        }

        return Draft::firstOrCreate(['draft_token' => $event->draft_token], ['state' => DraftState::Connecting]);
    }

    /** Token-less lines: the one draft currently picking, newest first. */
    private static function activeDraft(): ?Draft
    {
        return Draft::query()
            ->where('state', DraftState::Picking)
            ->orderByDesc(DraftPick::query()->selectRaw('max(shown_at)')->whereColumn('draft_id', 'drafts.id'))
            ->first();
    }

    private static function created(LogEvent $event): void
    {
        self::draftFor($event);
    }

    private static function standing(LogEvent $event): void
    {
        $json = RepairJson::firstObject($event->raw_text);
        $draft = self::draftFor($event);

        if (! $json || ! $draft) {
            return;
        }

        $startedAt = isset($json['StartDate']) ? Carbon::parse($json['StartDate'])->utc() : null;
        $league = ResolveDraftLeague::run((int) $json['LeagueID'], (int) $json['CourseID'], $startedAt, self::leagueTokenFor((int) $json['LeagueID']));

        $draft->fill([
            'league_id' => $league->id,
            'mtgo_draft_id' => $draft->mtgo_draft_id ?? ($json['DraftGroupID'] ?? null),
            'started_at' => $draft->started_at ?? $startedAt,
        ])->save();

        ResolveLeagueSetCode::run($draft->league()->firstOrFail());
    }

    private static function joined(LogEvent $event): void
    {
        $json = RepairJson::firstObject($event->raw_text);
        $draft = self::draftFor($event);

        if (! $json || ! $draft) {
            return;
        }

        if (! $draft->league_id) {
            $league = ResolveDraftLeague::run((int) $json['LeagueID'], null, null, self::leagueTokenFor((int) $json['LeagueID']));
            $draft->league_id = $league->id;
        }

        $draft->mtgo_draft_id ??= $json['DraftGroupID'] ?? null;
        $draft->save();

        ResolveLeagueSetCode::run($draft->league()->firstOrFail());
    }

    private static function podState(LogEvent $event): void
    {
        $json = RepairJson::firstObject($event->raw_text);
        $draft = self::draftFor($event);

        if (! $json || ! $draft) {
            return;
        }

        $pod = $json['Pods'][0] ?? null;
        $players = $pod['Players'] ?? [];
        $localLoginId = self::localLoginId($event);
        $seatIndex = null;

        foreach (array_values($players) as $i => $player) {
            if ($localLoginId !== null && (int) $player['LoginID'] === $localLoginId) {
                $seatIndex = $i;
            }
        }

        $draft->fill([
            'mtgo_draft_id' => $draft->mtgo_draft_id ?? ($json['DraftID'] ?? null),
            'pod_token' => $pod['Token'] ?? null,
            'seat_count' => max(1, count($players)),
            'seat_index' => $seatIndex,
            'booster_catalog_id' => $players[0]['BoosterInfo'][0]['CatalogID'] ?? null,
        ])->save();
    }

    private static function pendingPick(LogEvent $event): void
    {
        $json = RepairJson::firstObject($event->raw_text);
        $draft = self::draftFor($event);

        if (! $json || ! $draft || empty($json['PendingPick'])) {
            return;
        }

        $pending = $json['PendingPick'];
        $cards = array_map('intval', $pending['CardsAvailable'] ?? []);
        $count = count($cards);

        if ($count === 0) {
            return;
        }

        $packSize = max($draft->pack_size, $count);
        $packNumber = (int) ($pending['BoosterInfo']['BoosterIndex'] ?? 0) + 1;
        $pickNumber = $packSize + 1 - $count;
        $ordinal = ($packNumber - 1) * $packSize + $pickNumber;

        $deadline = isset($pending['EndTime']) ? Carbon::parse($pending['EndTime'])->utc() : null;
        $shownAt = $deadline
            ? ConvertMtgoTimestamp::run($deadline->copy(), substr($event->raw_text, 0, 8))
            : $event->logged_at;

        $pick = DraftPick::firstOrNew(['draft_id' => $draft->id, 'ordinal' => $ordinal]);
        $pick->fill([
            'pack_number' => $packNumber,
            'pick_number' => $pickNumber,
            'pack_id' => $pending['PackID'] ?? $pick->pack_id,
            'direction' => $pending['Direction'] ?? $pick->direction,
            'cards_available' => $cards,
            'reservations' => $pick->reservations ?? [],
            'shown_at' => $pick->shown_at ?? $shownAt,
            'deadline_at' => $deadline ?? $pick->deadline_at,
        ]);
        $isNew = ! $pick->exists;
        $pick->save();

        $draft->fill([
            'state' => $draft->state === DraftState::Finished ? DraftState::Finished : DraftState::Picking,
            'pack_size' => $packSize,
            'picks_expected' => $packSize * 3,
            'mtgo_draft_id' => $draft->mtgo_draft_id ?? ($json['DraftID'] ?? null),
        ])->save();

        CreateMissingCards::run($cards);

        if ($isNew) {
            DraftPickPending::dispatch($draft->id, $ordinal);
        }
    }

    private static function selection(LogEvent $event): void
    {
        if (! preg_match('/Reserved - (?<reserved>[\d,]*) ~ Committed - (?<committed>[\d,]*)/', $event->raw_text, $m)) {
            return;
        }

        $draft = self::activeDraft();
        if (! $draft) {
            return;
        }

        $pick = $draft->picks()->whereNull('picked_catalog_id')->orderByDesc('ordinal')->first();
        if (! $pick) {
            return;
        }

        $at = ($event->logged_at ?? $event->created_at)->toIso8601String();

        if ($m['reserved'] !== '') {
            $reservations = $pick->reservations;
            $entry = ['catalog_id' => (int) explode(',', $m['reserved'])[0], 'at' => $at];

            /** Replay safety: the same log line applied twice must not double the trail. */
            if (! in_array($entry, $reservations, true)) {
                $reservations[] = $entry;
                $pick->reservations = $reservations;
            }
        }

        if ($m['committed'] !== '') {
            $pick->picked_catalog_id = (int) explode(',', $m['committed'])[0];
            $pick->picked_at ??= $event->logged_at;
        }

        $pick->save();
    }

    private static function committed(LogEvent $event): void
    {
        $json = RepairJson::firstObject($event->raw_text);
        $draft = self::draftFor($event);

        if (! $json || ! $draft) {
            return;
        }

        $made = $json['PicksMade'] ?? [];
        $last = end($made);
        if (! $last) {
            return;
        }

        $catalogId = (int) ($last['Selections'][0]['CatalogID'] ?? 0);
        $cardId = (int) ($last['Selections'][0]['CardID'] ?? 0);
        $packId = isset($last['PackID']) ? (int) $last['PackID'] : null;

        if (self::commitAlreadyApplied($draft, $packId, $catalogId, $cardId)) {
            return;
        }

        $pick = $draft->picks()
            ->where('pack_id', $packId)
            ->whereNull('picked_card_id')
            ->orderBy('ordinal')
            ->first()
            ?? $draft->picks()->whereNull('picked_card_id')->orderBy('ordinal')->first();

        if (! $pick) {
            return;
        }

        $wasCommitted = $pick->picked_card_id !== null;

        $pick->fill([
            'picked_catalog_id' => $catalogId ?: $pick->picked_catalog_id,
            'picked_card_id' => $cardId ?: null,
            'picked_at' => isset($last['Time']) ? Carbon::parse($last['Time'])->utc() : $pick->picked_at,
        ])->save();

        if (! $wasCommitted) {
            DraftPickCommitted::dispatch($draft->id, $pick->ordinal);
        }
    }

    /**
     * True when this commit has already been projected onto its own pick.
     *
     * Replay safety for an in-flight draft: the pack_id lookup above only
     * finds picks with no picked_card_id, so on a second pass it misses and
     * the fallback would stamp this card onto whichever pick is still
     * pending, a different pack entirely. picked_card_id is the column this
     * branch writes, so it is the marker; picked_catalog_id alone is not,
     * because the plain-text selection line writes that first, before the
     * server ack this method projects.
     */
    private static function commitAlreadyApplied(Draft $draft, ?int $packId, int $catalogId, int $cardId): bool
    {
        if (! $catalogId) {
            return false;
        }

        return $draft->picks()
            ->where('picked_catalog_id', $catalogId)
            ->when($packId !== null, fn ($q) => $q->where('pack_id', $packId))
            ->when($packId === null, fn ($q) => $q->whereNotNull('picked_at'))
            ->when($cardId !== 0, fn ($q) => $q->where('picked_card_id', $cardId))
            ->when($cardId === 0, fn ($q) => $q->whereNotNull('picked_at'))
            ->exists();
    }

    private static function ended(LogEvent $event): void
    {
        $json = RepairJson::firstObject($event->raw_text);
        $draft = self::draftFor($event);

        if (! $json || ! $draft) {
            return;
        }

        $wasFinished = $draft->state === DraftState::Finished;
        ReconcileDraftFromEnded::run($draft, $json['Picks'] ?? [], $event->logged_at ?? now());

        if ($draft->league_id) {
            ResolveLeagueSetCode::run($draft->league()->firstOrFail());
        }

        if (! $wasFinished) {
            DraftEnded::dispatch($draft->id);
        }
    }

    private static function stateChanged(LogEvent $event): void
    {
        if (! str_contains($event->raw_text, 'to DraftFinishedState')) {
            return;
        }

        $draft = self::draftFor($event);
        if ($draft && $draft->state !== DraftState::Finished) {
            $draft->update(['state' => DraftState::Finished, 'ended_at' => $draft->ended_at ?? $event->logged_at]);
        }
    }

    private static function poolGranted(LogEvent $event): void
    {
        if (! preg_match('/for league: (?<league>\d+)/', $event->raw_text, $m)) {
            return;
        }

        preg_match_all('/CatalogID: (?<id>\d+), AddedQuantity: (?<qty>\d+)/', $event->raw_text, $rows, PREG_SET_ORDER);
        $granted = [];
        foreach ($rows as $row) {
            $granted[(int) $row['id']] = ($granted[(int) $row['id']] ?? 0) + (int) $row['qty'];
        }

        $draft = Draft::query()
            ->whereHas('league', fn ($q) => $q->where('event_id', (int) $m['league']))
            ->latest('id')
            ->first() ?? self::activeDraft();

        if (! $draft) {
            return;
        }

        $picked = $draft->poolCounts();
        ksort($picked);
        ksort($granted);

        if ($picked !== $granted) {
            Log::channel('pipeline')->warning("ApplyDraftEvent: pool grant differs from picks for draft #{$draft->id}", [
                'granted_total' => array_sum($granted),
                'picked_total' => array_sum($picked),
            ]);
        }

        LinkUnlinkedDrafts::linkByLeagueId($draft, (int) $m['league']);
    }

    private static function leagueTokenFor(int $leagueId): ?string
    {
        return LogEvent::query()
            ->where('event_type', 'league_joined')
            ->where('match_id', (string) $leagueId)
            ->orderByDesc('logged_at')
            ->value('match_token');
    }

    private static function localLoginId(LogEvent $event): ?int
    {
        return LogEvent::query()
            ->where('event_type', LogEventType::DRAFT_LEAGUE_STANDING->value)
            ->where('draft_token', $event->draft_token)
            ->get()
            ->map(fn (LogEvent $e) => RepairJson::firstObject($e->raw_text)['LoginID'] ?? null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->first();
    }
}
