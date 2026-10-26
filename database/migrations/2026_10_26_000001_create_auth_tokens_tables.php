<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tokens opacos (não JWT): só o sha256 é persistido, o valor cru vai só no cookie httpOnly.
     */
    public function up(): void {
        Schema::create('access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            // todos os tokens nascidos da mesma sessão original compartilham family_id;
            // reuso de um token já rotacionado revoga a família inteira (sinal de roubo)
            $table->uuid('family_id')->index();
            $table->boolean('revoked')->default(false);
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('access_tokens');
    }
};
