<?php

use App\Models\MtgoMatch;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MtgoMatch::query()
            ->where('state', 'complete')
            ->where(function ($q) {
                $q->where('games_won', '>', 0)->orWhere('games_lost', '>', 0);
            })
            ->whereHas('games', fn ($q) => $q->whereNull('won'))
            ->with('games')
            ->each(function (MtgoMatch $match) {
                $knownWins = $match->games->filter(fn ($g) => $g->won === true)->count();
                $knownLosses = $match->games->filter(fn ($g) => $g->won === false)->count();
                $missingWins = $match->games_won - $knownWins;
                $missingLosses = $match->games_lost - $knownLosses;

                foreach ($match->games->filter(fn ($g) => is_null($g->won)) as $game) {
                    if ($missingWins > 0) {
                        $game->update(['won' => true]);
                        $missingWins--;
                    } elseif ($missingLosses > 0) {
                        $game->update(['won' => false]);
                        $missingLosses--;
                    }
                }
            });
    }
};
