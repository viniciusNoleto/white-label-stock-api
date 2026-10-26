<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Primeira conta de acesso (não há cadastro público), a partir de SEED_ADMIN_* no .env.
 */
class AdminUserSeeder extends Seeder {

    public function run(): void {
        $admin = config('seed.admin');
        $email = mb_strtolower(trim((string)$admin['email']));

        if (!$admin['name'] || !$email || !$admin['password']) {
            $this->command?->warn('SEED_ADMIN_NAME/SEED_ADMIN_EMAIL/SEED_ADMIN_PASSWORD not set — skipping admin user creation.');

            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin user \"{$email}\" already exists, skipping.");

            return;
        }

        User::create([
            'name' => $admin['name'],
            'email' => $email,
            'password' => $admin['password'],
        ]);

        $this->command?->info("Admin user \"{$email}\" created.");
    }
}
