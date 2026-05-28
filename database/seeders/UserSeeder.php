<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@mmcrespo.pt'],
            ['name' => 'Administrador MMCrespo', 'password' => Hash::make('password')]
        );
        $admin->syncRoles(['admin']);

        $tecnico = User::firstOrCreate(
            ['email' => 'tecnico@mmcrespo.pt'],
            ['name' => 'Técnico Teste', 'password' => Hash::make('password')]
        );
        $tecnico->syncRoles(['tecnico']);

        $ns = User::firstOrCreate(
            ['email' => 'ns@mmcrespo.pt'],
            ['name' => 'Nadador Salvador Teste', 'password' => Hash::make('password')]
        );
        $ns->syncRoles(['nadador_salvador']);
    }
}