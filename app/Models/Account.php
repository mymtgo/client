<?php

namespace App\Models;

use App\Events\AccountCreated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'tracked' => 'boolean',
    ];

    public function decks(): HasMany
    {
        return $this->hasMany(Deck::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeTracked(Builder $query): Builder
    {
        return $query->where('tracked', true);
    }

    /**
     * Set this account as active, deactivating all others.
     */
    public function activate(): void
    {
        static::where('active', true)->update(['active' => false]);
        $this->update(['active' => true]);
    }

    /**
     * Find or create an account and activate it.
     */
    public static function registerAndActivate(string $username): static
    {
        $account = static::firstOrCreate(
            ['username' => $username],
            ['tracked' => true]
        );

        $account->activate();

        if ($account->wasRecentlyCreated) {
            AccountCreated::dispatch($account);
        }

        return $account;
    }
}
