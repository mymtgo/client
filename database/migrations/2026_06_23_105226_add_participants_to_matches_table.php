<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matches')) {
            return;
        }

        Schema::table('matches', function (Blueprint $table) {
            if (! Schema::hasColumn('matches', 'account_id')) {
                $table->foreignId('account_id')->nullable()->after('id')
                    ->constrained('accounts')->nullOnDelete();
                $table->index('account_id');
            }

            if (! Schema::hasColumn('matches', 'opponent_id')) {
                $table->foreignId('opponent_id')->nullable()->after('account_id')
                    ->constrained('opponents')->nullOnDelete();
                $table->index('opponent_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opponent_id');
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
