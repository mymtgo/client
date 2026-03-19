<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->unsignedInteger('event_id')->nullable()->after('token')->index();
            $table->dateTime('joined_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropIndex(['event_id']);
            $table->dropColumn(['event_id', 'joined_at']);
        });
    }
};
