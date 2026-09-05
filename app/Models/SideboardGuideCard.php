<?php

namespace App\Models;

use App\Enums\SideboardDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sideboard_guide_id
 * @property string $oracle_id
 * @property SideboardDirection $direction
 * @property int $quantity
 * @property-read SideboardGuide $guide
 */
class SideboardGuideCard extends Model
{
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => SideboardDirection::class,
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<SideboardGuide, $this> */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(SideboardGuide::class, 'sideboard_guide_id');
    }
}
