<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class LocalUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('O usuario de teste so pode ser criado em ambiente local/testing.');
        }

        $password = config('local.seed_user.password');

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Defina SEED_USER_PASSWORD no .env com pelo menos 12 caracteres.');
        }

        User::firstOrCreate(
            ['email' => config('local.seed_user.email')],
            [
                'name' => config('local.seed_user.name'),
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'role' => 'admin',
                'active' => true,
            ],
        );
    }
}
