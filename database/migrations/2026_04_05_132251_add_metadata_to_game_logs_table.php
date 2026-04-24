<?php

use App\Actions\Matches\ExtractGameResults;
use App\Models\GameLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existing = Schema::getColumnListing('game_logs');

        Schema::table('game_logs', function (Blueprint $table) use ($existing) {
            if (! in_array('first_timestamp', $existing, true)) {
                $table->timestamp('first_timestamp')->nullable()->after('file_path');
            }
            if (! in_array('players', $existing, true)) {
                $table->json('players')->nullable()->after('first_timestamp');
            }

            if (! Schema::hasIndex('game_logs', ['first_timestamp'])) {
                $table->index('first_timestamp');
            }
        });

        // Backfill existing decoded game logs
        GameLog::whereNotNull('decoded_entries')
            ->whereNull('first_timestamp')
            ->chunkById(500, function ($logs) {
                foreach ($logs as $log) {
                    $entries = $log->decoded_entries;
                    if (empty($entries)) {
                        continue;
                    }

                    $players = ExtractGameResults::detectPlayers($entries);

                    $log->update([
                        'first_timestamp' => $entries[0]['timestamp'] ?? null,
                        'players' => $players,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('game_logs', function (Blueprint $table) {
            $table->dropIndex(['first_timestamp']);
            $table->dropColumn(['first_timestamp', 'players']);
        });
    }
};
