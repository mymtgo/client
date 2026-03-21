<?php

namespace App\Listeners\Pipeline;

use App\Actions\Logs\IngestLog;
use App\Actions\Pipeline\IngestGameState;
use App\Facades\Mtgo;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\ChildProcess\MessageReceived;

class HandleFileChange
{
    public function handle(MessageReceived $event): void
    {
        Log::channel('pipeline')->debug('HandleFileChange: MessageReceived', [
            'alias' => $event->alias,
            'data_type' => gettype($event->data),
            'data_preview' => is_string($event->data) ? mb_substr($event->data, 0, 200) : '[array]',
        ]);

        if ($event->alias !== 'file-watcher') {
            return;
        }

        if (! Mtgo::canRun()) {
            Log::channel('pipeline')->warning('HandleFileChange: canRun=false');

            return;
        }

        $data = is_array($event->data) ? $event->data : json_decode($event->data, true);

        if (! $data || ! isset($data['type'], $data['path'])) {
            Log::channel('pipeline')->warning('HandleFileChange: unparseable data', [
                'raw' => is_string($event->data) ? mb_substr($event->data, 0, 300) : json_encode($event->data),
            ]);

            return;
        }

        Log::channel('pipeline')->info('HandleFileChange: dispatching', [
            'type' => $data['type'],
            'path' => basename($data['path']),
        ]);

        match ($data['type']) {
            'log_changed' => IngestLog::run($data['path']),
            'game_log_changed' => IngestGameState::run($data['path']),
            'file_added' => self::handleFileAdded($data['path']),
            default => null,
        };
    }

    private static function handleFileAdded(string $path): void
    {
        $basename = basename($path);

        if (str_contains($basename, 'Match_GameLog_') && str_ends_with($basename, '.dat')) {
            IngestGameState::run($path);
        } else {
            IngestLog::run($path);
        }
    }
}
