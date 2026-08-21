<?php

namespace App\Actions\Cards;

use App\Data\Front\DeckWinrateData;
use App\Data\Front\ExternalCardStatsResponse;
use App\Data\Front\ExternalOpponentData;
use App\Exceptions\ExternalCardStatsUnavailable;
use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\DataCollection;

final class FetchExternalCardStats
{
    public static function run(
        Archetype $archetype,
        string $format,
        ?int $opponentArchetypeId,
        ?bool $onPlay,
        ?bool $isPostboard,
        string $perspective,
    ): ExternalCardStatsResponse {
        $opponentUuid = $opponentArchetypeId !== null
            ? Archetype::query()->whereKey($opponentArchetypeId)->value('uuid')
            : null;

        $params = array_filter([
            'player_archetype_uuid' => $archetype->uuid,
            'opponent_archetype_uuid' => $opponentUuid,
            'format' => $format,
            'on_play' => $onPlay !== null ? (int) $onPlay : null,
            'is_postboard' => $isPostboard !== null ? (int) $isPostboard : null,
            'perspective' => $perspective,
        ], fn ($v) => $v !== null);

        try {
            $response = Http::mymtgoApi()->timeout(10)
                ->get(config('mymtgo_api.url').'/api/card-stats', $params);

        } catch (ConnectionException $e) {
            throw new ExternalCardStatsUnavailable('network', 0, $e);
        }

        if ($response->status() === 404) {
            return ExternalCardStatsResponse::createEmpty();
        }

        if ($response->failed()) {
            throw new ExternalCardStatsUnavailable('http', $response->status());
        }

        $json = $response->json();
        if (! is_array($json)
            || ! isset($json['stats'], $json['archetype_winrate'], $json['opponents'])
            || ! is_array($json['stats'])
            || ! is_array($json['archetype_winrate'])
            || ! is_array($json['opponents'])) {
            throw new ExternalCardStatsUnavailable('malformed', $response->status());
        }

        return self::hydrate($json);
    }

    private static function hydrate(array $json): ExternalCardStatsResponse
    {
        $oracleIds = collect($json['stats'])->pluck('oracle_id')->all();
        $cards = Card::query()
            ->whereIn('oracle_id', $oracleIds)
            ->whereNotNull('oracle_id')
            ->get()
            ->keyBy('oracle_id');

        $stats = collect($json['stats'])
            ->map(function (array $row) use ($cards): array {
                $oracleId = $row['oracle_id'];
                $card = $cards->get($oracleId);

                return [
                    'name' => $card?->name ?? 'Unknown',
                    'oracleId' => $oracleId,
                    'colorIdentity' => $card?->color_identity,
                    'type' => $card?->type,
                    'image' => $card && $card->local_image
                        ? Storage::disk('cards')->url($card->local_image)
                        : $card?->image,
                    'isSideboard' => false,
                    'totalGames' => (int) ($row['games'] ?? 0),
                    'totalPossible' => 0,
                    'totalKept' => (int) ($row['kept']['samples'] ?? 0),
                    'keptGames' => (int) ($row['kept']['samples'] ?? 0),
                    'keptWon' => (int) ($row['kept']['wins'] ?? 0),
                    'keptLost' => (int) ($row['kept']['samples'] ?? 0) - (int) ($row['kept']['wins'] ?? 0),
                    'totalSeen' => (int) ($row['seen']['samples'] ?? 0),
                    'seenGames' => (int) ($row['seen']['samples'] ?? 0),
                    'seenWon' => (int) ($row['seen']['wins'] ?? 0),
                    'seenLost' => (int) ($row['seen']['samples'] ?? 0) - (int) ($row['seen']['wins'] ?? 0),
                    'totalCast' => (int) ($row['cast']['samples'] ?? 0),
                    'castGames' => (int) ($row['cast']['samples'] ?? 0),
                    'castWon' => (int) ($row['cast']['wins'] ?? 0),
                    'castLost' => (int) ($row['cast']['samples'] ?? 0) - (int) ($row['cast']['wins'] ?? 0),
                    'postboardGames' => 0,
                    'sidedOutGames' => (int) ($row['sided_out']['samples'] ?? 0),
                    'sidedInGames' => (int) ($row['sided_in']['samples'] ?? 0),
                    'totalPlayed' => 0,
                    'playedGames' => 0,
                    'totalKicked' => 0,
                    'totalActivated' => 0,
                    'totalFlashback' => 0,
                    'totalMadness' => 0,
                    'totalEvoked' => 0,
                    'pregameRevealedGames' => 0,
                    'pregamePlayedGames' => 0,
                    'pregameGames' => (int) ($row['pregame']['samples'] ?? 0),
                    'pregameWon' => (int) ($row['pregame']['wins'] ?? 0),
                    'pregameLost' => (int) ($row['pregame']['samples'] ?? 0) - (int) ($row['pregame']['wins'] ?? 0),
                ];
            })
            ->all();

        $wr = $json['archetype_winrate'];
        $archetypeWinrate = new DeckWinrateData(
            wins: (int) ($wr['wins'] ?? 0),
            games: (int) ($wr['games'] ?? 0),
            rate: (float) ($wr['rate'] ?? 0.0),
        );

        $uuids = collect($json['opponents'])->pluck('uuid')->all();
        $idsByUuid = Archetype::query()->whereIn('uuid', $uuids)->pluck('id', 'uuid');

        $opponents = collect($json['opponents'])
            ->filter(fn (array $o) => $idsByUuid->has($o['uuid']))
            ->map(fn (array $o) => new ExternalOpponentData(
                id: (int) $idsByUuid[$o['uuid']],
                uuid: $o['uuid'],
                name: $o['name'],
            ))
            ->values()
            ->all();

        return new ExternalCardStatsResponse(
            stats: $stats,
            archetypeWinrate: $archetypeWinrate,
            opponents: ExternalOpponentData::collect($opponents, DataCollection::class),
            refreshedAt: $json['refreshed_at'] ?? null,
        );
    }
}
