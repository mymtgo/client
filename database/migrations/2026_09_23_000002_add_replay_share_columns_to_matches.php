<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared-replay link a match was published under, so reopening the
     * share dialog shows it without asking the server. One link covers every
     * game of the match.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('replay_share_uuid', 36)->nullable();
            $table->string('replay_share_url')->nullable();
            $table->timestamp('replay_shared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['replay_share_uuid', 'replay_share_url', 'replay_shared_at']);
        });
    }
};
