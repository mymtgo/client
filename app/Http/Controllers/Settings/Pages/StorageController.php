<?php

namespace App\Http\Controllers\Settings\Pages;

use App\Actions\Settings\ApplyCardImagesPath;
use App\Actions\Settings\MeasureLocalImagesSize;
use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use App\Jobs\MoveCardImages;
use App\Models\Card;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class StorageController extends Controller
{
    public function __invoke(): Response
    {
        $logPath = Mtgo::getLogPath();
        $dataPath = Mtgo::getLogDataPath();

        return Inertia::render('settings/Storage', [
            'currentPage' => 'storage',
            'logPath' => $logPath,
            'dataPath' => $dataPath,
            'logPathStatus' => ValidatePath::forLogs($logPath),
            'dataPathStatus' => ValidatePath::forData($dataPath),
            'watcherActive' => AppSettings::isWatcherActive(),
            'localImages' => AppSettings::downloadImagesLocally(),
            'localImagesSize' => MeasureLocalImagesSize::run(),
            'cardImages' => [
                'path' => ApplyCardImagesPath::root(),
                'isDefault' => AppSettings::cardImagesPath() === null,
                'missing' => ! ApplyCardImagesPath::available(),
                'moving' => (bool) Cache::get(MoveCardImages::MOVING_KEY, false),
                'error' => Cache::get(MoveCardImages::ERROR_KEY),
            ],
            'cardsTotal' => Card::query()->count(),
            // A stub with no name has never been filled from Scryfall, which
            // is the whole cards table on a device that took its history
            // from cloud sync.
            'cardsIncomplete' => Card::query()->whereNull('name')->count(),
        ]);
    }
}
