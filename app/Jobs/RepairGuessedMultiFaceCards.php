<?php

namespace App\Jobs;

use App\Actions\RegenerateCardGameStats;
use App\Models\Card;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Correct multi-face card rows that the card lookup filled with a neighbour.
 *
 * The lookup used to guess a back face for any row its id pass had not
 * resolved, including rows whose request simply failed. Two below Eldritch
 * Evolution (61460) sits a back-face id of Ulvenwald Captive, so a fresh
 * install whose first fetch failed stored Eldritch Evolution as Ulvenwald
 * Captive, and a resolved row is never looked up again. Only a multi-face
 * answer was ever accepted, so only rows named "A // B" can be wrong.
 *
 * Most installs hold nothing wrong, so this only asks: each such row's own
 * catalog id goes to the API, and a row is touched only when the API names a
 * different card for it. That row takes the right name and oracle id at
 * once and loses the wrong printing and art, which the card lookup then
 * refills. Card stats are keyed by oracle id, so they are rebuilt when any
 * row changed. A chunk the API did not answer throws, so the queue retries
 * it; rows already corrected match on the retry and are left alone.
 */
class RepairGuessedMultiFaceCards implements ShouldQueue
{
    use Queueable;

    /** API calls, so retry with backoff. */
    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 60];

    private const CHUNK = 50;

    public function __construct()
    {
        $this->onQueue('card_downloads');
    }

    public function handle(): void
    {
        $cards = Card::query()
            ->where('name', 'like', '% // %')
            ->whereNotNull('scryfall_id')
            ->get(['id', 'mtgo_id', 'name', 'oracle_id']);

        $repaired = 0;
        $unanswered = false;

        foreach ($cards->chunk(self::CHUNK) as $chunk) {
            $answers = $this->answersById($chunk);

            if ($answers === null) {
                $unanswered = true;

                continue;
            }

            foreach ($chunk as $card) {
                $answer = $answers->get((string) $card->mtgo_id);

                // No answer is a back face the API does not index, which the
                // back-face pass resolved from its front on purpose.
                if (! $answer || empty($answer['oracle_id']) || $answer['oracle_id'] === $card->oracle_id) {
                    continue;
                }

                $card->update([
                    'name' => $answer['name'] ?? $card->name,
                    'oracle_id' => $answer['oracle_id'],
                    'scryfall_id' => null,
                    'image' => null,
                    'art_crop' => null,
                    'local_image' => null,
                    'local_art_crop' => null,
                ]);

                $repaired++;
            }
        }

        if ($repaired > 0) {
            PopulateMissingCardData::dispatch();
            RegenerateCardGameStats::run();
        }

        if ($unanswered) {
            throw new RuntimeException('The API did not answer for every multi-face card; will retry.');
        }
    }

    /**
     * The API's answer for each catalog id in the chunk, or null when the
     * request failed and nothing can be concluded.
     *
     * @param  Collection<int, Card>  $chunk
     * @return Collection<string, array<string, mixed>>|null
     */
    private function answersById(Collection $chunk): ?Collection
    {
        try {
            $response = Http::mymtgoReference()->timeout(15)->post('/api/cards', [
                'ids' => $chunk->pluck('mtgo_id')->map(fn ($id) => (int) $id)->values(),
                'tokens' => [],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return collect($response->json())
            ->filter(fn ($answer) => isset($answer['value']))
            ->keyBy(fn ($answer) => (string) $answer['value']);
    }
}
