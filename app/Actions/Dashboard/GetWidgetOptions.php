<?php

namespace App\Actions\Dashboard;

use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Support\MtgoFormat;

/**
 * Option lists for the customise sheet: decks to pin, limited sets seen,
 * constructed formats played.
 */
class GetWidgetOptions
{
    /**
     * @return array{
     *     decks: array<int, array{id: int, name: string, format: string, coverArt: string|null}>,
     *     archetypes: array<int, array{id: int, name: string, format: string}>,
     *     sets: array<int, array{code: string, name: string}>,
     *     formats: array<int, array{value: string, label: string}>,
     * }
     */
    public static function run(?int $accountId): array
    {
        $decks = Deck::query()
            ->when($accountId, fn ($q, $id) => $q->where('account_id', $id))
            ->withoutLimited()
            ->with('cover')
            ->orderBy('name')
            ->get()
            ->map(fn (Deck $deck) => [
                'id' => $deck->id,
                'name' => $deck->name,
                'format' => MtgoFormat::display($deck->format),
                'coverArt' => $deck->cover?->art_crop_url,
            ])
            ->values()
            ->all();

        $archetypes = Archetype::query()
            ->whereIn('id', Deck::query()
                ->whereNotNull('archetype_id')
                ->when($accountId, fn ($q, $id) => $q->where('account_id', $id))
                ->select('archetype_id'))
            ->orderBy('name')
            ->get()
            ->map(fn (Archetype $archetype) => [
                'id' => $archetype->id,
                'name' => $archetype->name,
                'format' => MtgoFormat::display($archetype->format),
            ])
            ->values()
            ->all();

        $setCodes = League::query()->limited()->whereNotNull('set_code')->distinct()->orderBy('set_code')->pluck('set_code');
        $setNames = Card::query()
            ->whereIn('set_code', $setCodes)
            ->whereNotNull('set_name')
            ->get(['set_code', 'set_name'])
            ->unique('set_code')
            ->keyBy('set_code');

        $sets = $setCodes->map(fn (string $code) => [
            'code' => $code,
            'name' => $setNames[$code]->set_name ?? $code,
        ])->values()->all();

        $formats = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q->forAccount($id))
            ->notLimitedFormat()
            ->distinct()
            ->orderBy('format')
            ->pluck('format')
            ->map(fn (string $f) => ['value' => $f, 'label' => MtgoFormat::display($f)])
            ->values()
            ->all();

        return ['decks' => $decks, 'archetypes' => $archetypes, 'sets' => $sets, 'formats' => $formats];
    }
}
