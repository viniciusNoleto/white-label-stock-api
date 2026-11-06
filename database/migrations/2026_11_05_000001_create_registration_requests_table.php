<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Cadastro público com aprovação interna: o pedido fica em `registration_requests` até um admin
     * aprovar (vira `users`) ou recusar (o pedido é excluído). Só admins gerenciam usuários.
     */
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        Schema::create('registration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 255)->unique();
            // já com hash (bcrypt): na aprovação é copiado como está para `users.password`
            $table->string('password', 255);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('registration_requests');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
