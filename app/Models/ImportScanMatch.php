<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property \Carbon\Carbon $started_at
 */
class ImportScanMatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'game_ids' => 'array',
            'confidence' => 'float',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(ImportScan::class, 'import_scan_id');
    }
}
