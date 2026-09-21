<?php

namespace App\Sidecar;

use Carbon\CarbonImmutable;

final readonly class SidecarStatus
{
    public function __construct(
        public string $state,
        public ?string $mtgoVersion,
        public ?string $sdkVersion,
        public ?string $sidecarVersion,
        public ?string $currentFile,
        public ?CarbonImmutable $heartbeat,
        public ?int $lastEventSeq,
        public ?string $lastError,
    ) {}

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        return new self(
            state: (string) ($json['state'] ?? 'unknown'),
            mtgoVersion: $json['mtgo_version'] ?? null,
            sdkVersion: $json['sdk_version'] ?? null,
            sidecarVersion: $json['sidecar_version'] ?? null,
            currentFile: $json['current_file'] ?? null,
            heartbeat: isset($json['heartbeat']) ? CarbonImmutable::parse($json['heartbeat'])->utc() : null,
            lastEventSeq: isset($json['last_event_seq']) ? (int) $json['last_event_seq'] : null,
            lastError: $json['last_error'] ?? null,
        );
    }

    public function isStale(int $seconds = 10): bool
    {
        return $this->heartbeat === null || $this->heartbeat->diffInSeconds(now(), absolute: true) > $seconds;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'mtgo_version' => $this->mtgoVersion,
            'sdk_version' => $this->sdkVersion,
            'sidecar_version' => $this->sidecarVersion,
            'current_file' => $this->currentFile,
            'heartbeat' => $this->heartbeat?->toIso8601ZuluString(),
            'last_event_seq' => $this->lastEventSeq,
            'last_error' => $this->lastError,
            'stale' => $this->isStale(),
        ];
    }
}
