<?php declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Contas reais de produção
        $daniel = User::firstOrCreate(
            ['email' => 'daniel@mmcrespo.pt'],
            [
                'name'       => 'Daniel Paz',
                'first_name' => 'Daniel',
                'last_name'  => 'Paz',
                'password'   => Hash::make(env('ADMIN_PASSWORD_DANIEL', 'piscinasmmcrespo26')),
                'email_verified_at' => now(),
                'must_change_password' => true,
            ]
        );
        $daniel->syncRoles(['admin']);

        $marcio = User::firstOrCreate(
            ['email' => 'marcio@mmcrespo.pt'],
            [
                'name'       => 'Márcio',
                'first_name' => 'Márcio',
                'last_name'  => '',
                'password'   => Hash::make(env('ADMIN_PASSWORD_MARCIO', 'piscinasmmcrespo26')),
                'email_verified_at' => now(),
                'must_change_password' => true,
            ]
        );
        $marcio->syncRoles(['admin']);

        // Contas de teste — apenas em desenvolvimento
        if (! app()->isProduction()) {
            $admin = User::firstOrCreate(
                ['email' => 'admin@mmcrespo.pt'],
                [
                    'name'     => 'Admin Teste',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ]
            );
            $admin->syncRoles(['admin']);

            $tec = User::firstOrCreate(
                ['email' => 'tecnico@mmcrespo.pt'],
                [
                    'name'     => 'Técnico Teste',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ]
            );
            $tec->syncRoles(['tecnico']);

            $ns = User::firstOrCreate(
                ['email' => 'ns@mmcrespo.pt'],
                [
                    'name'     => 'Nadador Salvador Teste',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ]
            );
            $ns->syncRoles(['nadador_salvador']);

            $gestor = User::firstOrCreate(
                ['email' => 'gestor@mmcrespo.pt'],
                [
                    'name'     => 'Gestor Teste',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ]
            );
            $gestor->syncRoles(['gestor']);
        }
    }
}
