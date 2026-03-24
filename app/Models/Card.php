<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $quantity
 * @property bool|null $sideboard
 * @property object{quantity: int, sideboard: bool|string}|null $pivot
 */
class Card extends Model
{
    use HasFactory;

    protected $guarded = [];
}
