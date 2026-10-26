<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Données de développement local uniquement (jamais lancé en prod, où l'admin
 * se crée avec `php artisan baby:create-admin`).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@baby-krebs.test'],
            ['name' => 'Admin', 'password' => 'password'],
        );

        $this->call(DemoTournamentSeeder::class);
    }
}
