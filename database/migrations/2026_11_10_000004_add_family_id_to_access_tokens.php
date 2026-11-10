<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * O access token passa a saber de qual família de refresh nasceu: na detecção de reuso a família
     * inteira cai, incluindo os access tokens ainda válidos (antes sobravam até 15 min de acesso).
     * Anulável (expand): tokens emitidos antes desta migration ficam sem família e expiram sozinhos.
     */
    public function up(): void {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->uuid('family_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->dropColumn('family_id');
        });
    }
};
