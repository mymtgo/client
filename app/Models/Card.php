<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * @property int|null $quantity
 * @property bool|null $sideboard
 * @property string|null $local_image
 * @property string|null $local_art_crop
 * @property string|null $image_url Resolved image URL (local-first, falls back to remote)
 * @property string|null $art_crop_url Resolved art crop URL (local-first, falls back to remote)
 */
class Card extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Resolve image URL, preferring local file when available.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->local_image ? Storage::disk('cards')->url($this->local_image) : $this->attributes['image'] ?? null
        );
    }

    /**
     * Resolve art crop URL, preferring local file when available.
     */
    protected function artCropUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->local_art_crop ? Storage::disk('cards')->url($this->local_art_crop) : $this->attributes['art_crop'] ?? null
        );
    }
}
