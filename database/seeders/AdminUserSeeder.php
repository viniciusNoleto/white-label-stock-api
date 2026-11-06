<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Primeira conta de acesso — o admin, que aprova os pedidos de cadastro — a partir de SEED_ADMIN_* no .env.
 */
class AdminUserSeeder extends Seeder {

    public function run(): void {
        $admin = config('seed.admin');
        $email = mb_strtolower(trim((string)$admin['email']));

        if (!$admin['name'] || !$email || !$admin['password']) {
            $this->command?->warn('SEED_ADMIN_NAME/SEED_ADMIN_EMAIL/SEED_ADMIN_PASSWORD not set — skipping admin user creation.');

            return;
        }

        $existing = User::where('email', $email)->first();

        if ($existing) {
            // Contas criadas antes da coluna `is_admin` passam a ser admin.
            if (!$existing->is_admin) {
                $existing->update(['is_admin' => true]);
                $this->command?->info("User \"{$email}\" promoted to admin.");

                return;
            }

            $this->command?->info("Admin user \"{$email}\" already exists, skipping.");

            return;
        }

        User::create([
            'name' => $admin['name'],
            'email' => $email,
            'password' => $admin['password'],
            'is_admin' => true,
        ]);

        $this->command?->info("Admin user \"{$email}\" created.");
    }
}
