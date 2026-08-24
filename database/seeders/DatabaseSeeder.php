<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlatformSettingsSeeder::class,
        ]);

        User::factory()->create([
            'email' => 'user@example.test',
            'name' => 'Website Owner',
            'email_verified_at' => now(),
            'is_active' => true,
            'is_admin' => false,
            'role' => 'owner',
        ]);

        User::factory()->create([
            'email' => 'admin@example.test',
            'name' => 'Admin',
            'email_verified_at' => now(),
            'is_active' => true,
            'is_admin' => true,
            'role' => 'admin',
        ]);
    }
}
