<?php

namespace App\Updates;

use App\Actions\Cards\ReresolveTokenPrintings;
use App\Models\Card;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Re-resolve this install's tokens once the API has MTGO's token catalog.
 *
 * The catalog reaches the API when the owner presses the Debug upload, which
 * may be after this build ships. Clearing tokens before then would resolve
 * them by name again and land on the same wrong printings, so this asks the
 * API about the install's own token ids first and throws until it knows at
 * least one: RunAppUpdates only records an update that returns, so it runs
 * again next launch.
 */
class ReresolveTokensAfterCatalogImport extends AppUpdate
{
    /** Enough ids to prove the catalog is in, few enough for one small request. */
    private const PROBE_SIZE = 50;

    public function run(): void
    {
        $ids = Card::query()->where('type', 'like', 'Token%')->limit(self::PROBE_SIZE)->pluck('mtgo_id')->all();

        if ($ids === []) {
            return;
        }

        $known = collect(Http::mymtgoApi()->timeout(15)->post('/api/cards', ['ids' => $ids, 'tokens' => []])->json())
            ->contains(fn ($row) => isset($row['value']));

        if (! $known) {
            throw new RuntimeException('The API does not know these tokens yet; retrying next launch.');
        }

        ReresolveTokenPrintings::run();
    }
}
