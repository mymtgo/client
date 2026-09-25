<?php

namespace App\Actions\Sidecar;

use App\Models\Game;
use App\Models\GameFieldDiff;
use App\Models\MtgoMatch;
use App\Sidecar\Resolution;

class RecordFieldDiff
{
    /**
     * Upserts or clears the one disagreement row for a subject and field.
     * Shared by every sidecar authority decision so the debug page reads
     * one table.
     */
    public static function run(MtgoMatch $match, ?Game $game, string $field, Resolution $resolution, mixed $logValue, mixed $sidecarValue): void
    {
        $query = GameFieldDiff::query()
            ->where('match_id', $match->id)
            ->where('field', $field)
            ->when($game, fn ($q) => $q->where('game_id', $game->id), fn ($q) => $q->whereNull('game_id'));

        if (! $resolution->disagree) {
            $query->delete();

            return;
        }

        $existing = (clone $query)->first();

        $attributes = [
            'match_id' => $match->id,
            'game_id' => $game?->id,
            'field' => $field,
            'log_value' => is_array($logValue) ? $logValue : ['value' => $logValue],
            'sidecar_value' => is_array($sidecarValue) ? $sidecarValue : ['value' => $sidecarValue],
            'chosen_source' => $resolution->chosenSource,
        ];

        if ($existing) {
            $existing->update($attributes);
        } else {
            GameFieldDiff::create($attributes);
        }
    }
}
