<?php

namespace App\Http\Controllers\Setup;

use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $logPath = Mtgo::getLogPath();
        $dataPath = Mtgo::getLogDataPath();

        return Inertia::render('setup/Index', [
            'logPath' => $logPath,
            'dataPath' => $dataPath,
            'logPathStatus' => ValidatePath::forLogs($logPath),
            'dataPathStatus' => ValidatePath::forData($dataPath),
            'archetypeCount' => Archetype::query()->count(),
            'deckCount' => Deck::query()->count(),
            'setupSkippedArchetypes' => AppSettings::setupSkippedArchetypes(),
            'setupSkippedDecks' => AppSettings::setupSkippedDecks(),
        ]);
    }
}
