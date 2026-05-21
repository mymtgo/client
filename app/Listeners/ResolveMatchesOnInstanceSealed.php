<?php

namespace App\Listeners;

use App\Actions\Matches\ReconcileStuckMatches;
use App\Events\LogInstanceSealed;
use Closure;

class ResolveMatchesOnInstanceSealed
{
    public function __construct(private ?Closure $resolver = null) {}

    public function handle(LogInstanceSealed $event): void
    {
        ($this->resolver ?? fn () => ReconcileStuckMatches::run())();
    }
}
