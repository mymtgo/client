<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueKind;
use App\Models\Card;
use App\Models\League;
use App\Models\LogEvent;

class ResolveLeagueSetCode
{
    /**
     * Fill leagues.set_code (and kind for limited formats). First source
     * that answers wins; an existing value is never replaced.
     */
    public static function run(League $league, ?string $matchPlayFormatCd = null): void
    {
        $changes = [];

        $kind = LeagueKind::fromFormatCode($matchPlayFormatCd);

        if ($kind !== LeagueKind::Constructed && $league->kind === LeagueKind::Constructed) {
            $changes['kind'] = $kind;
        }

        if (! $league->set_code) {
            $setCode = self::fromPlayFormat((string) $matchPlayFormatCd)
                ?? self::fromLeaguePanel($league)
                ?? self::fromPicks($league);

            if ($setCode) {
                $changes['set_code'] = $setCode;
            }
        }

        if ($changes) {
            $league->update($changes);
        }
    }

    /**
     * "DHOBHOBHOB" (draft, one code per booster), "S6FRA" (sealed, booster
     * count then the code) or "HOBx3" (league panel).
     * Codes are not always three characters, so find the shortest repeating
     * unit rather than splitting in threes.
     */
    public static function fromPlayFormat(string $playFormatCd): ?string
    {
        if ($playFormatCd === '') {
            return null;
        }

        if (preg_match('/^(?<code>[A-Z0-9]{2,5})x\d+$/', $playFormatCd, $m)) {
            return $m['code'];
        }

        if (preg_match('/^S\d+(?<code>[A-Z0-9]{2,5})$/', $playFormatCd, $m)) {
            return $m['code'];
        }

        if (! str_starts_with($playFormatCd, 'D')) {
            return null;
        }

        $body = substr($playFormatCd, 1);
        $len = strlen($body);

        for ($unit = 2; $unit <= 5; $unit++) {
            if ($len % $unit !== 0) {
                continue;
            }

            $code = substr($body, 0, $unit);

            if (str_repeat($code, intdiv($len, $unit)) === $body && intdiv($len, $unit) >= 2) {
                return $code;
            }
        }

        return null;
    }

    private static function fromLeaguePanel(League $league): ?string
    {
        if (! $league->event_id) {
            return null;
        }

        $raw = LogEvent::query()
            ->where('event_type', 'league_joined')
            ->where('match_id', (string) $league->event_id)
            ->orderByDesc('logged_at')
            ->value('raw_text');

        if ($raw && preg_match('/PlayFormatCd=\s*(?<fmt>\S+)/', $raw, $m)) {
            return self::fromPlayFormat($m['fmt']);
        }

        return null;
    }

    private static function fromPicks(League $league): ?string
    {
        $draft = $league->draft;
        if (! $draft) {
            return null;
        }

        $ids = $draft->picks()->whereNotNull('picked_catalog_id')->pluck('picked_catalog_id');
        if ($ids->isEmpty()) {
            return null;
        }

        return Card::query()
            ->whereIn('mtgo_id', $ids->map(fn ($id) => (string) $id))
            ->whereNotNull('set_code')
            ->pluck('set_code')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();
    }
}
