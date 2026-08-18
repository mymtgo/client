<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Casting-method counters mirroring ExtractCardsFromGameLog::CAST_SUFFIX_FLAGS.
     *
     * @var list<string>
     */
    private array $columns = [
        'warp', 'free_cast', 'bargained', 'dashed', 'bestowed', 'replicated',
        'spectacle', 'rebound', 'escaped', 'ninjutsu', 'suspended', 'buyback',
        'disturb', 'foretold', 'retraced', 'mayhem', 'miracle', 'gifted',
        'casualty',
    ];

    public function up(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (! Schema::hasColumn('card_game_stats', $column)) {
                    $table->integer($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('card_game_stats', function (Blueprint $table) {
            $table->dropColumn($this->columns);
        });
    }
};
