<?php

namespace App\Console\Commands;

use App\Actions\Cards\CreateMissingCards;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMissingCards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:backfill-missing-cards';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $gamePlayers = DB::table('game_player')->select('deck_json')->get();

        $cards = [];

        foreach ($gamePlayers as $gamePlayer) {
            $deck = collect(json_decode($gamePlayer->deck_json, true))->map(
                fn ($card) => $card['mtgo_id']
            );
            $cards = [
                ...$cards,
                ...$deck,
            ];
        }

        CreateMissingCards::run($cards);
    }
}
