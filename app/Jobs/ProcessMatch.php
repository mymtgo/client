<?php

namespace App\Jobs;

use App\Actions\CreateMatchGames;
use App\Actions\DetermineMatchArchetypes;
use App\Actions\MatchGameDeck;
use App\Actions\StoreMatchResults;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessMatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $jsonFeed, public string $states)
    {
        //
    }

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 3600;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        $matchData = json_decode($this->jsonFeed, true);

        return $matchData['matchToken'];
    }

    /**
     * Execute the job.
     */
    public function handle(): MtgoMatch
    {
        $matchData = json_decode($this->jsonFeed, true);

        $completedMatch = MtgoMatch::where('token', $matchData['matchToken'])
            ->where('status', '=', 'completed')
            ->withTrashed()->first();

        if ($completedMatch) {
            return $completedMatch;
        }

        DB::beginTransaction();

        $match = \App\Actions\CreateMatch::execute($matchData);

        CreateMatchGames::run($match, $matchData['games']);

        MatchGameDeck::run($match);

        $match = StoreMatchResults::execute($match, $this->states);

        if ($match->isCompleted()) {
            DetermineMatchArchetypes::run($match);
        }

        DB::commit();

        return $match;
    }
}
