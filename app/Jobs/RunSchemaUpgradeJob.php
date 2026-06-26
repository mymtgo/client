<?php

namespace App\Jobs;

use App\Actions\Upgrade\RunParticipantBackfill;
use App\Facades\AppSettings;
use App\Models\SchemaUpgrade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunSchemaUpgradeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $upgradeId,
    ) {
        $this->onQueue('importer');
    }

    public function handle(): void
    {
        $tracker = SchemaUpgrade::find($this->upgradeId);

        if (! $tracker) {
            return;
        }

        RunParticipantBackfill::run($tracker);

        AppSettings::setDataSchemaVersion(SchemaUpgrade::TARGET_DATA_VERSION);
    }

    public function failed(\Throwable $e): void
    {
        SchemaUpgrade::find($this->upgradeId)?->markFailed($e->getMessage());

        Log::channel('pipeline')->error('RunSchemaUpgradeJob failed', [
            'upgrade_id' => $this->upgradeId,
            'error' => $e->getMessage(),
        ]);
    }
}
