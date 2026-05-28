<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            InstallationSeeder::class,
            PoolSeeder::class,
            ProductSeeder::class,
            UserSeeder::class,
        ]);
    }
}