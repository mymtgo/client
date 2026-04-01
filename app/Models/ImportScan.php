<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportScan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'total' => 'integer',
        ];
    }

    public function deckVersion(): BelongsTo
    {
        return $this->belongsTo(DeckVersion::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ImportScanMatch::class);
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
