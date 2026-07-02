<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local binding between this install and a cloud account: who the user
 * is (cloud user_id), which MTGO identity they are locked to (strict 1:1,
 * non-editable), and the server-authoritative plan. Written after the
 * client-auth PKCE flow resolves; read by ResolveLocalIdentity. OAuth
 * tokens live in encrypted AppSettings (client-auth), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('mtgo_player_id')->nullable();
            $table->string('mtgo_username');
            $table->string('plan', 16)->default('free');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_accounts');
    }
};
