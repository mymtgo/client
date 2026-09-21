<?php

namespace App\Sidecar;

use Carbon\CarbonImmutable;

final readonly class SidecarEventLine
{
    public const SUPPORTED_MAJOR = 1;

    public const SOURCE = 'mtgo_sidecar';

    /**
     * @param  array<string, mixed>  $data
     * @param  ?array<string, mixed>  $ref
     */
    public function __construct(
        public int $v,
        public string $session,
        public CarbonImmutable $sessionStartedAt,
        public int $seq,
        public CarbonImmutable $ts,
        public string $type,
        public ?string $game,
        public ?string $match,
        public bool $verified,
        public array $data,
        public ?array $ref,
    ) {}

    /**
     * Insert row for GameEvent::insertOrIgnore. JSON columns are encoded
     * here because bulk inserts bypass Eloquent casts.
     *
     * @return array<string, mixed>
     */
    public function toRow(int $logInstanceId): array
    {
        return [
            'log_instance_id' => $logInstanceId,
            'session' => $this->session,
            'session_started_at' => $this->sessionStartedAt->format('Y-m-d H:i:s.v'),
            'seq' => $this->seq,
            'source' => self::SOURCE,
            'type' => $this->type,
            'ts' => $this->ts->format('Y-m-d H:i:s.v'),
            'game_mtgo_id' => $this->game,
            'match_mtgo_id' => $this->match,
            'verified' => $this->verified,
            'data' => json_encode($this->data, JSON_THROW_ON_ERROR),
            'ref' => $this->ref === null ? null : json_encode($this->ref, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ];
    }
}
