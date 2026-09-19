<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    // The migration names the table `sync_state` (singular, one row per
    // resource type rather than one row per "state"); Eloquent's default
    // pluralized inference would otherwise look for `sync_states`.
    protected $table = 'sync_state';

    protected $guarded = [];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];
}
