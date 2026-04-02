<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ArchetypeCard extends Pivot
{
    protected $casts = [
        'sideboard' => 'bool',
    ];
}
