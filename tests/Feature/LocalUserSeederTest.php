<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LocalUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class LocalUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_a_hashed_password_and_is_idempotent(): void
    {
        config(['local.seed_user.password' => 'SenhaLocalTeste123!']);
        (new LocalUserSeeder)->run();
        $this->seed(LocalUserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(Hash::check('SenhaLocalTeste123!', User::sole()->password));
    }

    public function test_reseeding_preserves_an_existing_users_password(): void
    {
        config(['local.seed_user.password' => 'SenhaLocalTeste123!']);
        $this->seed(LocalUserSeeder::class);
        User::sole()->update(['password' => 'SenhaAlterada123!']);

        $this->seed(LocalUserSeeder::class);

        $this->assertTrue(Hash::check('SenhaAlterada123!', User::sole()->password));
    }

    public function test_seeder_rejects_a_missing_password(): void
    {
        config(['local.seed_user.password' => null]);
        $this->expectException(RuntimeException::class);

        $this->seed(LocalUserSeeder::class);
    }

    public function test_test_user_cannot_be_seeded_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);

        (new LocalUserSeeder)->run();
    }
}
