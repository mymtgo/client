<?php

use App\Models\Archetype;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Archetype::query()->updateOrCreate(
            ['uuid' => '00000000-0000-0000-0000-000000000001'],
            [
                'name' => 'Homebrew',
                'format' => null,
                'color_identity' => null,
                'manual' => false,
                'is_fallback' => true,
                'decklist_downloaded_at' => now(),
            ],
        );

        Archetype::query()->updateOrCreate(
            ['uuid' => '00000000-0000-0000-0000-000000000002'],
            [
                'name' => 'Rogue',
                'format' => null,
                'color_identity' => null,
                'manual' => false,
                'is_fallback' => true,
                'decklist_downloaded_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Archetype::query()
            ->whereIn('uuid', [
                '00000000-0000-0000-0000-000000000001',
                '00000000-0000-0000-0000-000000000002',
            ])
            ->delete();
    }
};
