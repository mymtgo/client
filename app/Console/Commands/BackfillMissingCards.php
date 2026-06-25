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
        $cards = DB::table('game_decks')
            ->select('deck_json')
            ->get()
            ->flatMap(fn ($gd) => collect(json_decode($gd->deck_json, true))->pluck('mtgo_id'))
            ->unique()
            ->values()
            ->toArray();

        CreateMissingCards::run($cards);
    }
}
