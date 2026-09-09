<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Services\DailyRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NadadorSalvadorLeiriaRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $ns;

    private Installation $leiria;

    private Pool $competicao;

    private Pool $lazer;

    private Pool $infantil;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
            'volume' => 900.0,
            'temp_min' => 26.0,
            'temp_max' => 28.0,
            'ordem_bombas' => 2,
            'ordem_filtros' => 3,
        ]);
        $this->lazer = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Lazer',
            'volume' => 500.0,
            'temp_min' => 28.0,
            'temp_max' => 30.0,
            'ordem_bombas' => 3,
            'ordem_filtros' => 2,
        ]);
        $this->infantil = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Infantil',
            'volume' => 100.0,
            'temp_min' => 29.0,
            'temp_max' => 31.0,
            'ordem_bombas' => 1,
            'ordem_filtros' => 1,
        ]);

        $this->ns = User::factory()->create(['name' => 'Nadador Leiria']);
        $this->ns->assignRole(UserRole::NADADOR_SALVADOR);
        $this->ns->ns_permissions = [NSPermission::REGISTO_DIARIO];
        $this->ns->save();
    }

    public function test_ns_submits_via_quick_action_for_one_pool(): void
    {
        Storage::fake('public');
        $this->ns->piscinas()->attach([$this->competicao->id, $this->lazer->id, $this->infantil->id]);

        $file = UploadedFile::fake()->create('board.jpg', 100, 'image/jpeg');

        Livewire::withQueryParams(['pool' => $this->competicao->id, 'quick' => 1])
            ->actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                'ns_foto' => [$file],
                'pools' => [
                    $this->competicao->id => [
                        'ns_ph' => 7.2,
                        'ns_cloro_livre' => 1.2,
                        'ns_cloro_total' => 1.5,
                        'ns_temperatura' => 27.0,
                        'banhistas' => 15,
                    ],
                ],
            ])
            ->call('validarERegistosGuardar')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $this->competicao->id,
            'user_id' => $this->ns->id,
            'ns_ph' => 7.2,
            'banhistas' => 15,
        ]);
    }

    public function test_ns_submits_all_three_pools(): void
    {
        Storage::fake('public');
        $this->ns->piscinas()->attach([$this->competicao->id, $this->lazer->id, $this->infantil->id]);

        $file = UploadedFile::fake()->create('board.jpg', 100, 'image/jpeg');

        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                'ns_foto' => [$file],
                'pools' => [
                    $this->competicao->id => [
                        'ns_ph' => 7.2,
                        'ns_cloro_livre' => 1.2,
                        'ns_cloro_total' => 1.5,
                        'ns_temperatura' => 27.0,
                        'banhistas' => 15,
                    ],
                    $this->lazer->id => [
                        'ns_ph' => 7.3,
                        'ns_cloro_livre' => 1.4,
                        'ns_cloro_total' => 1.7,
                        'ns_temperatura' => 29.0,
                        'banhistas' => 8,
                    ],
                    $this->infantil->id => [
                        'ns_ph' => 7.4,
                        'ns_cloro_livre' => 1.5,
                        'ns_cloro_total' => 1.8,
                        'ns_temperatura' => 30.0,
                        'banhistas' => 5,
                    ],
                ],
            ])
            ->call('validarERegistosGuardar')
            ->assertActionMounted('confirmarCriacao')
            ->callMountedAction();

        $this->assertDatabaseCount('daily_records', 3);
    }

    public function test_service_rejects_unassigned_pool_with_validation_exception(): void
    {
        // Swimmer has only Competição assigned, but not Infantil
        $this->ns->piscinas()->attach([$this->competicao->id]);

        $service = app(DailyRecordService::class);

        $this->expectException(ValidationException::class);

        $service->createRecords($this->ns, [
            'installation_id' => $this->leiria->id,
            'ns_foto' => null,
            'pools' => [
                $this->infantil->id => [
                    'ns_ph' => 7.2,
                    'ns_cloro_livre' => 1.2,
                    'ns_cloro_total' => 1.5,
                    'ns_temperatura' => 27.0,
                ],
            ],
        ]);
    }

    public function test_service_rejects_empty_pools_with_validation_exception(): void
    {
        $this->ns->piscinas()->attach([$this->competicao->id]);

        $service = app(DailyRecordService::class);

        $this->expectException(ValidationException::class);

        $service->createRecords($this->ns, [
            'installation_id' => $this->leiria->id,
            'ns_foto' => null,
            'pools' => [],
        ]);
    }

    public function test_service_handles_empty_ns_foto_array_without_crashing(): void
    {
        $this->ns->piscinas()->attach([$this->competicao->id]);

        $service = app(DailyRecordService::class);

        $record = $service->createRecords($this->ns, [
            'installation_id' => $this->leiria->id,
            'ns_foto' => [], // Empty array when upload is cleared or empty
            'pools' => [
                $this->competicao->id => [
                    'ns_ph' => 7.2,
                    'ns_cloro_livre' => 1.2,
                    'ns_cloro_total' => 1.5,
                    'ns_temperatura' => 27.0,
                ],
            ],
        ]);

        $this->assertNotNull($record);
        $this->assertNull($record->ns_foto);
    }

    public function test_service_handles_postgres_string_pool_ids_without_aborting_403(): void
    {
        $this->ns->piscinas()->attach([$this->competicao->id]);

        $service = app(DailyRecordService::class);

        // When PostgreSQL PDO returns pool IDs as strings e.g. ['1']
        // We simulate the service check with string keys or string IDs in user_pools
        $record = $service->createRecords($this->ns, [
            'installation_id' => $this->leiria->id,
            'ns_foto' => null,
            'pools' => [
                (string) $this->competicao->id => [
                    'ns_ph' => 7.2,
                    'ns_cloro_livre' => 1.2,
                    'ns_cloro_total' => 1.5,
                    'ns_temperatura' => 27.0,
                ],
            ],
        ]);

        $this->assertNotNull($record);
    }

    public function test_ns_quick_action_with_out_of_bounds_reading_confirms_and_saves(): void
    {
        Storage::fake('public');
        $this->ns->piscinas()->attach([$this->competicao->id]);

        $file = UploadedFile::fake()->create('board.jpg', 100, 'image/jpeg');

        // pH 8.4 is in the red / out of limits for Competição (max is typically 7.6)
        Livewire::withQueryParams(['pool' => $this->competicao->id, 'quick' => 1])
            ->actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                'ns_foto' => [$file],
                'pools' => [
                    $this->competicao->id => [
                        'ns_ph' => 8.4,
                        'ns_cloro_livre' => 1.2,
                        'ns_cloro_total' => 1.5,
                        'ns_temperatura' => 27.0,
                        'banhistas' => 10,
                    ],
                ],
            ])
            ->call('validarERegistosGuardar')
            ->assertActionMounted('confirmarCriacao')
            ->callMountedAction();

        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $this->competicao->id,
            'user_id' => $this->ns->id,
            'ns_ph' => 8.4,
        ]);
    }
}
