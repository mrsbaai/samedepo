<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'email' => 'user@example.test',
            'email_verified_at' => now(),
            'is_active' => true,
            'is_admin' => false,
        ]);

        User::factory()->create([
            'email' => 'admin@example.test',
            'email_verified_at' => now(),
            'is_active' => true,
            'is_admin' => true,
        ]);
    }
}
