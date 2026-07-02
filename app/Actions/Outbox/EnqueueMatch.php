<?php

namespace App\Actions\Outbox;

use App\Data\ProjectedMatch\ProjectedMatchData;
use App\Models\Outbox;

/**
 * Idempotent enqueue: one outbox row per match_key. The file_version bumps
 * only when the substantive payload changed — volatile envelope fields
 * (compiled_at, file_version itself) are excluded from the comparison so a
 * recompile of identical data never re-pends a synced row.
 */
final class EnqueueMatch
{
    private const VOLATILE_ENVELOPE_FIELDS = ['compiled_at', 'file_version'];

    public function run(ProjectedMatchData $dto): Outbox
    {
        $payload = $dto->toArray();

        $existing = Outbox::query()->where('match_key', $dto->match_key)->first();

        if ($existing === null) {
            $payload['file_version'] = 1;

            return Outbox::create([
                'match_key' => $dto->match_key,
                'payload' => $payload,
                'file_version' => 1,
                'status' => 'pending',
            ]);
        }

        if ($this->substance($payload) === $this->substance($existing->payload)) {
            return $existing;
        }

        $version = $existing->file_version + 1;
        $payload['file_version'] = $version;

        $existing->update([
            'payload' => $payload,
            'file_version' => $version,
            'status' => 'pending',
        ]);

        return $existing->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function substance(array $payload): array
    {
        foreach (self::VOLATILE_ENVELOPE_FIELDS as $field) {
            unset($payload[$field]);
        }

        return $payload;
    }
}
