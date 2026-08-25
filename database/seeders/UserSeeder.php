<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Contas reais de produção
        // Lançar exceção se passwords não definidas em produção (evita fallback fraco)
        $passwordDaniel = app()->isProduction()
            ? (env('ADMIN_PASSWORD_DANIEL') ?: throw new \RuntimeException('ADMIN_PASSWORD_DANIEL não está definida nas variáveis de ambiente de produção.'))
            : env('ADMIN_PASSWORD_DANIEL', 'dev_changeme_daniel');

        $passwordMarcio = app()->isProduction()
            ? (env('ADMIN_PASSWORD_MARCIO') ?: throw new \RuntimeException('ADMIN_PASSWORD_MARCIO não está definida nas variáveis de ambiente de produção.'))
            : env('ADMIN_PASSWORD_MARCIO', 'dev_changeme_marcio');

        $daniel = User::firstOrCreate(
            ['email' => 'daniel@mmcrespo.pt'],
            [
                'name' => 'Daniel Paz',
                'first_name' => 'Daniel',
                'last_name' => 'Paz',
                'password' => Hash::make($passwordDaniel),
                'email_verified_at' => now(),
                'must_change_password' => true,
            ]
        );
        if (! $daniel->hasRole('admin')) {
            $daniel->assignRole('admin');
        }

        $marcio = User::firstOrCreate(
            ['email' => 'marcio@mmcrespo.pt'],
            [
                'name' => 'Márcio',
                'first_name' => 'Márcio',
                'last_name' => '',
                'password' => Hash::make($passwordMarcio),
                'email_verified_at' => now(),
                'must_change_password' => true,
            ]
        );
        if (! $marcio->hasRole('admin')) {
            $marcio->assignRole('admin');
        }

        // Contas de teste — apenas em 'local' ou 'testing' (não em staging ou produção)
        if (app()->environment('local', 'testing')) {
            $admin = User::firstOrCreate(
                ['email' => 'admin@mmcrespo.pt'],
                [
                    'name' => 'Admin Teste',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ]
            );
            $admin->syncRoles(['admin']);

            $tec = User::firstOrCreate(
                ['email' => 'tecnico@mmcrespo.pt'],
                [
                    'name' => 'Técnico Teste',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ]
            );
            $tec->syncRoles(['tecnico']);

            $ns = User::firstOrCreate(
                ['email' => 'ns@mmcrespo.pt'],
                [
                    'name' => 'Nadador Salvador Teste',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ]
            );
            $ns->syncRoles(['nadador_salvador']);

            $gestor = User::firstOrCreate(
                ['email' => 'gestor@mmcrespo.pt'],
                [
                    'name' => 'Gestor Teste',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ]
            );
            $gestor->syncRoles(['gestor']);
        }
    }
}
