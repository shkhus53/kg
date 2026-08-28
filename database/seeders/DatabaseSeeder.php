<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        User::factory()->operator()->create([
            'name' => 'Operator User',
            'email' => 'operator@example.com',
        ]);

        User::factory()->viewer()->create([
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
        ]);
    }
}
