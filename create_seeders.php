<?php

$seeders = [
    'InstallationSeeder' => <<<'PHP'
<?php

namespace Database\Seeders;

use App\Models\Installation;
use Illuminate\Database\Seeder;

class InstallationSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['name' => 'Leiria',          'address' => 'Complexo de Piscinas de Leiria, Leiria'],
            ['name' => 'Maceira',         'address' => 'Piscina Municipal de Maceira, Maceira, Leiria'],
            ['name' => 'Caranguejeira',   'address' => 'Piscina Municipal de Caranguejeira, Caranguejeira, Leiria'],
        ];

        foreach ($data as $row) {
            Installation::firstOrCreate(['name' => $row['name']], $row + ['active' => true]);
        }
    }
}
PHP,

    'PoolSeeder' => <<<'PHP'
<?php

namespace Database\Seeders;

use App\Models\Installation;
use App\Models\Pool;
use Illuminate\Database\Seeder;

class PoolSeeder extends Seeder
{
    public function run(): void
    {
        $leiria        = Installation::where('name', 'Leiria')->sole();
        $maceira       = Installation::where('name', 'Maceira')->sole();
        $caranguejeira = Installation::where('name', 'Caranguejeira')->sole();

        $pools = [
            [
                'installation_id' => $leiria->id,
                'name'     => 'Competição',
                'type'     => 'competicao',
                'temp_min' => 26.0,
                'temp_max' => 27.0,
            ],
            [
                'installation_id' => $leiria->id,
                'name'     => 'Lazer',
                'type'     => 'lazer',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
            ],
            [
                'installation_id' => $leiria->id,
                'name'     => 'Infantil',
                'type'     => 'infantil',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
            ],
            [
                'installation_id' => $maceira->id,
                'name'     => 'Maceira',
                'type'     => 'polivalente',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
            ],
            [
                'installation_id' => $caranguejeira->id,
                'name'     => 'Caranguejeira',
                'type'     => 'polivalente',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
            ],
        ];

        foreach ($pools as $row) {
            Pool::firstOrCreate(
                ['installation_id' => $row['installation_id'], 'name' => $row['name']],
                $row + ['active' => true]
            );
        }
    }
}
PHP,

    'ProductSeeder' => <<<'PHP'
<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Hipoclorito de Sódio',      'unit' => 'L',  'category' => 'Desinfeção'],
            ['name' => 'Minorador de pH',            'unit' => 'kg', 'category' => 'Correção pH'],
            ['name' => 'Floculante Líquido',         'unit' => 'L',  'category' => 'Clarificação'],
            ['name' => 'Algicida',                   'unit' => 'L',  'category' => 'Tratamento'],
            ['name' => 'Germicida',                  'unit' => 'L',  'category' => 'Desinfeção'],
            ['name' => 'Dicloro Granulado',          'unit' => 'kg', 'category' => 'Desinfeção'],
            ['name' => 'Tricloro Granulado',         'unit' => 'kg', 'category' => 'Desinfeção'],
            ['name' => 'Desincrustante de Bordas',   'unit' => 'L',  'category' => 'Manutenção'],
        ];

        foreach ($products as $row) {
            Product::firstOrCreate(['name' => $row['name']], $row + ['active' => true]);
        }
    }
}
PHP,

    'RolesAndPermissionsSeeder' => <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin',            'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico',          'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
    }
}
PHP,

    'UserSeeder' => <<<'PHP'
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
PHP,

    'DatabaseSeeder' => <<<'PHP'
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
PHP,
];

foreach ($seeders as $name => $content) {
    file_put_contents(__DIR__ . '/database/seeders/' . $name . '.php', $content);
}
echo "Seeders created.";
