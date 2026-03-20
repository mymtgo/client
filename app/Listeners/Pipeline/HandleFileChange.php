<?php

namespace App\Listeners\Pipeline;

use App\Actions\Logs\IngestLog;
use App\Actions\Pipeline\IngestGameState;
use App\Facades\Mtgo;
use Native\Desktop\Events\ChildProcess\MessageReceived;

class HandleFileChange
{
    public function handle(MessageReceived $event): void
    {
        if ($event->alias !== 'file-watcher') {
            return;
        }

        if (! Mtgo::canRun()) {
            return;
        }

        $data = is_array($event->data) ? $event->data : json_decode($event->data, true);

        if (! $data || ! isset($data['type'], $data['path'])) {
            return;
        }

        match ($data['type']) {
            'log_changed' => IngestLog::run($data['path']),
            'game_log_changed' => IngestGameState::run($data['path']),
            default => null,
        };
    }
}
