<?php

namespace App\Console\Commands;

use App\Actions\DetermineMatchArchetypes;
use App\Models\MtgoMatch;
use Illuminate\Console\Command;

class SyncMatchArchetypes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-match-archetypes';

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
        $matches = MtgoMatch::get();

        foreach ($matches as $match) {
            DetermineMatchArchetypes::run($match);
        }
    }
}
