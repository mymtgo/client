<?php

namespace App\Http\Controllers\Settings\Pages;

use App\Actions\Settings\MeasureLocalImagesSize;
use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
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
        ]);
    }
}
