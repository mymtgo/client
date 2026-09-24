<?php

namespace App\Sidecar;

use Carbon\CarbonImmutable;

/**
 * Where the helper download stands. Lives in its own file, never in
 * settings.json: progress is written about once a second, and a torn
 * settings.json rebuilds itself offline and loses the user's tokens.
 */
final readonly class SidecarDownloadState
{
    public const IDLE = 'idle';

    public const DOWNLOADING = 'downloading';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const ERROR_NETWORK = 'network';

    public const ERROR_CHECKSUM = 'checksum';

    public const ERROR_QUARANTINED = 'quarantined';

    /** A download with no progress write for this long has lost its job (app killed, worker died). */
    public const STALE_SECONDS = 30;

    public function __construct(
        public string $status,
        public string $version,
        public ?string $runId = null,
        public int $bytes = 0,
        public ?int $total = null,
        public ?string $error = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}

    public static function idle(string $version): self
    {
        return new self(self::IDLE, $version);
    }

    public static function downloading(string $version, string $runId, int $bytes = 0, ?int $total = null): self
    {
        return new self(self::DOWNLOADING, $version, $runId, $bytes, $total, null, CarbonImmutable::now());
    }

    public static function ready(string $version, string $runId): self
    {
        return new self(self::READY, $version, $runId, updatedAt: CarbonImmutable::now());
    }

    public static function failed(string $version, ?string $runId, string $error): self
    {
        return new self(self::FAILED, $version, $runId, error: $error, updatedAt: CarbonImmutable::now());
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $updatedAt = null;

        if (isset($json['updated_at']) && is_string($json['updated_at'])) {
            try {
                $updatedAt = CarbonImmutable::parse($json['updated_at']);
            } catch (\Throwable) {
                $updatedAt = null;
            }
        }

        return new self(
            status: (string) ($json['status'] ?? self::IDLE),
            version: (string) ($json['version'] ?? ''),
            runId: isset($json['run_id']) ? (string) $json['run_id'] : null,
            bytes: (int) ($json['bytes'] ?? 0),
            total: isset($json['total']) ? (int) $json['total'] : null,
            error: isset($json['error']) ? (string) $json['error'] : null,
            updatedAt: $updatedAt,
        );
    }

    /** @return array{status: string, version: string, run_id: string|null, bytes: int, total: int|null, error: string|null, updated_at: string|null} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'version' => $this->version,
            'run_id' => $this->runId,
            'bytes' => $this->bytes,
            'total' => $this->total,
            'error' => $this->error,
            'updated_at' => $this->updatedAt?->toIso8601ZuluString(),
        ];
    }

    public function isFor(string $version): bool
    {
        return $this->version === $version;
    }

    public function isStale(): bool
    {
        if ($this->status !== self::DOWNLOADING) {
            return false;
        }

        return $this->updatedAt === null
            || $this->updatedAt->diffInSeconds(CarbonImmutable::now(), absolute: true) > self::STALE_SECONDS;
    }

    /** Whole percent, or null when the server sent no length. */
    public function progress(): ?int
    {
        if ($this->total === null || $this->total <= 0) {
            return null;
        }

        return (int) min(100, floor($this->bytes * 100 / $this->total));
    }
}
