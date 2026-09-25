<?php

namespace App\Actions\Matches;

use App\Actions\Decks\FindDeckVersionForSnapshot;
use App\Actions\Sidecar\ReadMatchSnapshot;
use App\Actions\Sidecar\RecordFieldDiff;
use App\Actions\Sidecar\ResolveFieldAuthority;
use App\Enums\LeagueKind;
use App\Models\Account;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarAuthorityFlags;
use App\Sidecar\SidecarPaths;

class ResolveMatchDeckFromSidecar
{
    /**
     * Link the match to the deck MTGO says was registered (spec 5.5), used
     * inline by DetermineMatchDeck. Returns false when the sidecar cannot
     * answer, so the log path decides.
     *
     * The cross-check is deck level and compares against the deck already
     * linked (by the log path, or an earlier sidecar answer): a different
     * version of the same deck is the bug being fixed, a different deck
     * altogether means the snapshot is not this match's. Nothing linked yet
     * passes.
     */
    public static function run(MtgoMatch $match): bool
    {
        return self::resolve($match, onlyNew: false);
    }

    /**
     * Late correction from ApplySidecarProjection: acts only on a snapshot
     * that has just arrived, so a decision is made once rather than on
     * every tick.
     */
    public static function correct(MtgoMatch $match): void
    {
        self::resolve($match, onlyNew: true);
    }

    private static function resolve(MtgoMatch $match, bool $onlyNew): bool
    {
        if (! SidecarAuthorityFlags::isOn('match_deck') || ! is_dir(SidecarPaths::directory())) {
            return false;
        }

        if ($match->manual || $match->submitted_at !== null || MtgoMatch::isLimitedFormatCode($match->format)) {
            return false;
        }

        $read = ReadMatchSnapshot::run($match, ReadMatchSnapshot::PHASE_STARTED);

        if ($read?->registeredDeck === null || ($onlyNew && ! $read->isNew)) {
            return false;
        }

        $version = FindDeckVersionForSnapshot::run($read->registeredDeck, Account::currentId());

        if ($version === null) {
            return false;
        }

        $current = $match->deck_version_id;
        $currentDeckId = $current === null ? null : DeckVersion::query()->whereKey($current)->value('deck_id');
        $crossOk = $currentDeckId === null || $currentDeckId === $version->deck_id;

        $resolution = ResolveFieldAuthority::run('match_deck', $current, $version->id, true, true, true, $crossOk);

        if ($resolution->disagree) {
            RecordFieldDiff::run($match, null, 'match_deck', $resolution, $current, $version->id);
        }

        if ($resolution->chosenSource !== 'sidecar') {
            return false;
        }

        if ($current !== $version->id) {
            $match->update(['deck_version_id' => $version->id]);
            self::followWithLeague($match, $current, $version->id);
        }

        return true;
    }

    /**
     * The league keeps the deck its matches were played with, so a later
     * log fallback does not re-split through step 2's deck filter. A
     * league with no deck yet gets this one. Manual and limited leagues
     * manage their own.
     */
    private static function followWithLeague(MtgoMatch $match, ?int $previousVersionId, int $versionId): void
    {
        $league = $match->league;

        if ($league === null || $league->manual || $league->kind !== LeagueKind::Constructed) {
            return;
        }

        if ($league->deck_version_id === null || ($previousVersionId !== null && $league->deck_version_id === $previousVersionId)) {
            $league->update(['deck_version_id' => $versionId]);
        }
    }
}
