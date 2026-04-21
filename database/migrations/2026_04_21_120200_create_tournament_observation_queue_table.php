<?php

use App\Models\LogEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tournament_observation_queue')) {
            return;
        }

        Schema::create('tournament_observation_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(LogEvent::class)->unique()->constrained()->cascadeOnDelete();
            $table->string('tournament_token')->nullable()->index();
            $table->string('match_token')->nullable()->index();
            $table->string('event_type')->index();
            $table->json('payload');
            $table->dateTime('client_observed_at');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_observation_queue');
    }
};
