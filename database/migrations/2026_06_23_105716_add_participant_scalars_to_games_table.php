<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('games')) {
            return;
        }

        Schema::table('games', function (Blueprint $table) {
            if (! Schema::hasColumn('games', 'local_on_play')) {
                $table->boolean('local_on_play')->nullable()->after('won');
            }
            if (! Schema::hasColumn('games', 'local_mulligans')) {
                $table->unsignedInteger('local_mulligans')->nullable()->after('local_on_play');
            }
            if (! Schema::hasColumn('games', 'opp_mulligans')) {
                $table->unsignedInteger('opp_mulligans')->nullable()->after('local_mulligans');
            }
            if (! Schema::hasColumn('games', 'local_dice')) {
                $table->unsignedInteger('local_dice')->nullable()->after('opp_mulligans');
            }
            if (! Schema::hasColumn('games', 'opp_dice')) {
                $table->unsignedInteger('opp_dice')->nullable()->after('local_dice');
            }
            if (! Schema::hasColumn('games', 'local_instance')) {
                $table->unsignedInteger('local_instance')->nullable()->after('opp_dice');
            }
            if (! Schema::hasColumn('games', 'opp_instance')) {
                $table->unsignedInteger('opp_instance')->nullable()->after('local_instance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn([
                'local_on_play', 'local_mulligans', 'opp_mulligans',
                'local_dice', 'opp_dice', 'local_instance', 'opp_instance',
            ]);
        });
    }
};
