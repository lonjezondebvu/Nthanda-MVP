<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
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
        if (app()->environment('production')) {
            throw new \RuntimeException('Demo accounts must not be seeded in production. Provision administrators securely.');
        }

        $class = SchoolClass::query()->updateOrCreate(['name' => 'Form 1'], ['position' => 1]);
        User::unguarded(fn () => User::updateOrCreate(['email' => 'admin@nthanda.test'], ['name' => 'Studio Administrator', 'role' => 'admin', 'password' => Hash::make('NthandaDemo!2026')]));
        User::unguarded(fn () => User::updateOrCreate(['email' => 'learner@nthanda.test'], ['name' => 'Demo Learner', 'role' => 'learner', 'school_class_id' => $class->id, 'disabilities' => [], 'password' => Hash::make('NthandaDemo!2026')]));
        $teacher = User::unguarded(fn () => User::updateOrCreate(['email' => 'teacher@nthanda.test'], ['name' => 'Demo Teacher', 'role' => 'teacher', 'password' => Hash::make('NthandaDemo!2026')]));
        $teacher->taughtClasses()->syncWithoutDetaching([$class->id]);
    }
}
