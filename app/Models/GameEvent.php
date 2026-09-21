<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $log_instance_id
 * @property string $session
 * @property CarbonImmutable $session_started_at
 * @property int $seq
 * @property string $source
 * @property string $type
 * @property CarbonImmutable $ts
 * @property ?string $game_mtgo_id
 * @property ?string $match_mtgo_id
 * @property bool $verified
 * @property array<string, mixed> $data
 * @property ?array<string, mixed> $ref
 */
class GameEvent extends Model
{
    /** @use HasFactory<GameEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'session_started_at' => 'immutable_datetime',
            'ts' => 'immutable_datetime',
            'seq' => 'integer',
            'verified' => 'boolean',
            'data' => 'array',
            'ref' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LogInstance, $this> */
    public function logInstance(): BelongsTo
    {
        return $this->belongsTo(LogInstance::class);
    }

    protected static function newFactory(): GameEventFactory
    {
        return GameEventFactory::new();
    }
}
