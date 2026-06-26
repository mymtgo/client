<?php

namespace App\Models;

use App\Facades\AppSettings;
use Illuminate\Database\Eloquent\Model;

class SchemaUpgrade extends Model
{
    public const TARGET_DATA_VERSION = 1;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'total' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Whether a schema upgrade is required.
     *
     * Short-circuits on the version check (fast path once upgraded) to avoid
     * hitting the database on every request after the upgrade is complete.
     */
    public static function needsUpgrade(): bool
    {
        return AppSettings::dataSchemaVersion() < self::TARGET_DATA_VERSION
            && MtgoMatch::query()->whereNull('account_id')->exists();
    }

    /**
     * Advance to a new stage, resetting progress to 0.
     *
     * Also transitions to 'running' and captures started_at on first call.
     */
    public function markStage(string $stage, int $total = 0): void
    {
        $updates = [
            'stage' => $stage,
            'progress' => 0,
            'total' => $total,
            'status' => 'running',
        ];

        if ($this->started_at === null) {
            $updates['started_at'] = now();
        }

        $this->update($updates);
    }

    /**
     * Mark the upgrade as failed with an error message.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
        ]);
    }

    /**
     * Mark the upgrade as successfully complete.
     */
    public function markComplete(): void
    {
        $this->update([
            'status' => 'complete',
            'completed_at' => now(),
        ]);
    }

    /**
     * Whether the upgrade has completed successfully.
     */
    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }
}
