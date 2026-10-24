<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        foreach (['admin' => 'Studio Administrator', 'learner' => 'Demo Learner'] as $role => $name) {
            User::unguarded(fn () => User::updateOrCreate(['email' => $role.'@nthanda.test'], ['name' => $name, 'role' => $role, 'password' => Hash::make('NthandaDemo!2026')]));
        }
    }
}
