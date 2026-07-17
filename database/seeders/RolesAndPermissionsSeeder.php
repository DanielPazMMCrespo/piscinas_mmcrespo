<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Constants\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin',            'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'gestor',           'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico',          'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
        // Sem permissões: atribuído a utilizadores desativados para bloquear o acesso
        // mantendo o histórico (daily_records, incidents) intacto.
        Role::firstOrCreate(['name' => UserRole::INATIVO, 'guard_name' => 'web']);
    }
}
