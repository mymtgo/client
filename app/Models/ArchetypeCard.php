<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $quantity
 * @property bool $sideboard
 */
class ArchetypeCard extends Pivot
{
    protected $casts = [
        'sideboard' => 'bool',
    ];
}
