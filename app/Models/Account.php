<?php

namespace App\Models;

use App\Events\AccountCreated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Account extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'tracked' => 'boolean',
    ];

    protected static ?Account $cachedCurrent = null;

    protected static bool $cachedCurrentLoaded = false;

    public function decks(): HasMany
    {
        return $this->hasMany(Deck::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Memoized current active account for the lifetime of the request.
     */
    public static function current(): ?self
    {
        if (! static::$cachedCurrentLoaded) {
            static::$cachedCurrent = static::query()->where('active', true)->first();
            static::$cachedCurrentLoaded = true;
        }

        return static::$cachedCurrent;
    }

    public static function currentId(): ?int
    {
        return static::current()?->id;
    }

    public static function flushCurrent(): void
    {
        static::$cachedCurrent = null;
        static::$cachedCurrentLoaded = false;
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
        DB::transaction(function () {
            static::where('id', '!=', $this->id)->update(['active' => false]);
            $this->update(['active' => true]);
        });
    }

    /**
     * Ensure there is always one active account.
     * Called via model boot — prevents deactivating the last account.
     */
    protected static function booted(): void
    {
        static::updating(function (Account $account) {
            if ($account->isDirty('active') && ! $account->active && static::count() === 1) {
                $account->active = true;
            }
        });

        static::saved(function () {
            static::flushCurrent();

            if (! static::where('active', true)->exists()) {
                static::first()?->update(['active' => true]);
            }
        });

        static::deleted(fn () => static::flushCurrent());
    }

    /**
     * Find or create an account and activate it.
     */
    public static function registerAndActivate(string $username): self
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
