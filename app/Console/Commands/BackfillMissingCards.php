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
        $cards = DB::table('game_player')
            ->select('deck_json')
            ->get()
            ->flatMap(fn ($gp) => collect(json_decode($gp->deck_json, true))->pluck('mtgo_id'))
            ->unique()
            ->values()
            ->toArray();

        CreateMissingCards::run($cards);
    }
}
