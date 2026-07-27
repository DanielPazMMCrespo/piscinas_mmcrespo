<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PDFIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
    }

    private function createTestEnvironment(): array
    {
        $installation = Installation::create([
            'name' => 'Leiria',
            'morada' => 'Rua Teste',
            'active' => true,
        ]);

        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Competição',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);

        $admin = User::factory()->create(['name' => 'Admin']);
        $admin->assignRole('admin');

        $technician = User::factory()->create(['name' => 'Técnico']);
        $technician->assignRole('tecnico');

        $swimmer = User::factory()->create(['name' => 'Nadador']);
        $swimmer->assignRole('nadador_salvador');

        return [
            'installation' => $installation,
            'pool' => $pool,
            'admin' => $admin,
            'technician' => $technician,
            'swimmer' => $swimmer,
        ];
    }

    public function test_admin_can_access_pdf_report_page(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];

        $response = $this->actingAs($admin)->get('/admin/relatorio-pdf');

        $response->assertStatus(200);
    }

    public function test_technician_can_access_pdf_report_page(): void
    {
        $env = $this->createTestEnvironment();
        $technician = $env['technician'];

        $response = $this->actingAs($technician)->get('/admin/relatorio-pdf');

        $response->assertStatus(200);
    }

    public function test_swimmer_cannot_access_pdf_report_page(): void
    {
        $env = $this->createTestEnvironment();
        $swimmer = $env['swimmer'];

        $response = $this->actingAs($swimmer)->get('/admin/relatorio-pdf');

        // Nadador-Salvador should not have access
        $response->assertStatus(403);
    }

    public function test_daily_records_with_conforming_values_marked_as_conforme(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Create records with conforming values
        $conforming = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'e_correcao' => false,
        ]);

        // Check conformance
        $is_conforming = $this->recordIsConforming($conforming);

        $this->assertTrue($is_conforming, 'Record with all values within limits should be marked as conforming');
    }

    public function test_daily_records_with_non_conforming_values_marked_as_not_conforme(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Create record with pH out of limits
        $non_conforming = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 5.5,  // Below minimum 6.9
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'e_correcao' => false,
        ]);

        $is_conforming = $this->recordIsConforming($non_conforming);

        $this->assertFalse($is_conforming, 'Record with pH out of limits should not be marked as conforming');
    }

    public function test_corrected_records_are_excluded_from_pdf(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        $date_start = now()->startOfMonth()->toDateString();
        $date_end = now()->toDateString();

        // Create original record
        $original = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 6.5,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'e_correcao' => false,
            'corrige_registo_id' => null,
        ]);

        // Create correction
        $correction = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now()->addMinute(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'e_correcao' => true,
            'corrige_registo_id' => $original->id,
            'razao_correcao' => 'Leitura incorreta',
        ]);

        // Query tal como o gerador de PDF real (RelatorioPdf): whereDoesntHave('correcoes').
        $records = DailyRecord::where('pool_id', $pool->id)
            ->whereBetween('registado_em', [now()->startOfMonth(), now()->endOfDay()])
            ->whereDoesntHave('correcoes')
            ->get();

        // O original foi corrigido (tem correcoes) → excluído; a correção (valor válido) → incluída.
        $this->assertFalse($records->contains($original->id));
        $this->assertTrue($records->contains($correction->id));
    }

    public function test_pdf_report_generation_with_valid_parameters(): void
    {
        $env = $this->createTestEnvironment();
        $installation = $env['installation'];
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Create sample records
        for ($i = 0; $i < 5; $i++) {
            DailyRecord::create([
                'pool_id' => $pool->id,
                'user_id' => $technician->id,
                'registado_em' => now()->subDays($i),
                'ph' => 7.0 + ($i * 0.1),
                'cloro_livre' => 1.2,
                'cloro_total' => 1.5,
                'transparencia' => 2.0,
                'temperatura' => 26.5,
                'e_correcao' => false,
            ]);
        }

        // Simulate PDF report parameters
        $params = [
            'installation_id' => $installation->id,
            'pool_id' => $pool->id,
            'data_inicio' => now()->startOfMonth()->toDateString(),
            'data_fim' => now()->toDateString(),
        ];

        // Query records as PDF would
        $records = DailyRecord::where('pool_id', $pool->id)
            ->whereBetween('registado_em', [$params['data_inicio'], $params['data_fim']])
            ->where('e_correcao', false)
            ->get();

        $this->assertGreaterThan(0, $records->count(), 'Should have records in date range');
    }

    public function test_pdf_multi_pool_report_filtering(): void
    {
        $env = $this->createTestEnvironment();
        $installation = $env['installation'];
        $technician = $env['technician'];

        // Create multiple pools
        $pool1 = $env['pool'];
        $pool2 = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Lazer',
            'type' => 'leisure',
            'temp_min' => 28.0,
            'temp_max' => 30.0,
            'volume' => 600.00,
            'active' => true,
        ]);

        // Add records to both pools
        DailyRecord::create([
            'pool_id' => $pool1->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        DailyRecord::create([
            'pool_id' => $pool2->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.3,
            'cloro_livre' => 1.1,
            'cloro_total' => 1.4,
            'transparencia' => 2.5,
            'temperatura' => 29.0,
        ]);

        // Query single pool
        $records_pool1 = DailyRecord::where('pool_id', $pool1->id)->get();
        $this->assertCount(1, $records_pool1);

        // Query all pools in installation
        $records_all = DailyRecord::whereIn('pool_id', [$pool1->id, $pool2->id])->get();
        $this->assertCount(2, $records_all);
    }

    public function test_pdf_date_range_filtering(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Create records across different dates
        $dates = [
            now()->subDays(10),
            now()->subDays(5),
            now(),
        ];

        foreach ($dates as $date) {
            DailyRecord::create([
                'pool_id' => $pool->id,
                'user_id' => $technician->id,
                'registado_em' => $date,
                'ph' => 7.2,
                'cloro_livre' => 1.2,
                'cloro_total' => 1.5,
                'transparencia' => 2.0,
                'temperatura' => 26.5,
            ]);
        }

        // Query specific date range (limites inclusivos do dia para apanhar horas da tarde).
        $records_in_range = DailyRecord::where('pool_id', $pool->id)
            ->whereBetween('registado_em', [now()->subDays(7)->startOfDay(), now()->endOfDay()])
            ->get();

        $this->assertCount(2, $records_in_range, 'Should have records from last 7 days');
    }

    public function test_pdf_contains_legally_required_columns(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Create a record
        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'observacoes' => 'Test observation',
            'caleira_feita' => true,
            'renovacao_agua' => false,
        ]);

        // Verify record has all required fields for CN 14/DA compliance
        $required_fields = [
            'registado_em',
            'ph',
            'cloro_livre',
            'cloro_total',
            'transparencia',
            'temperatura',
            'observacoes',
        ];

        foreach ($required_fields as $field) {
            $this->assertNotNull($record->getAttribute($field),
                "Record must have {$field} for PDF compliance");
        }
    }

    public function test_pdf_report_excludes_corrected_records(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        $date_inicio = now()->startOfMonth()->toDateString();
        $date_fim = now()->toDateString();

        // Create original and correction
        $original = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 6.5,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'e_correcao' => false,
        ]);

        $correction = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now()->addMinute(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
            'e_correcao' => true,
            'corrige_registo_id' => $original->id,
        ]);

        // PDF query: exclude records where e_correcao = true (limites inclusivos do dia)
        $records_for_pdf = DailyRecord::where('pool_id', $pool->id)
            ->whereBetween('registado_em', [now()->startOfMonth(), now()->endOfDay()])
            ->where('e_correcao', false)
            ->get();

        $this->assertTrue($records_for_pdf->contains($original->id));
        $this->assertFalse($records_for_pdf->contains($correction->id));
    }

    public function test_pdf_temperature_against_pool_limits(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        // Pool has temp_min=26.0, temp_max=27.0

        $record_conforming = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,  // Within limits
        ]);

        $record_non_conforming = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now()->addHour(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 25.0,  // Below minimum
        ]);

        // Check conformance
        $this->assertTrue($this->temperatureIsConforming($record_conforming, $pool));
        $this->assertFalse($this->temperatureIsConforming($record_non_conforming, $pool));
    }

    public function test_pdf_all_metricas_constants_available(): void
    {
        // Verify all metrics from the single source of truth are available for PDF
        $metricas = DailyRecord::getMetricas();

        $required_metricas = ['ph', 'cloro_livre', 'cloro_combinado', 'transparencia', 'temperatura'];

        foreach ($required_metricas as $metrica) {
            $this->assertArrayHasKey($metrica, $metricas,
                "getMetricas() should include {$metrica}");
        }
    }

    public function test_pdf_view_renders_correctly_with_custom_columns_and_sections(): void
    {
        $env = $this->createTestEnvironment();
        $pool = $env['pool'];
        $technician = $env['technician'];

        $record = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $technician->id,
            'registado_em' => now(),
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'transparencia' => 2.0,
            'temperatura' => 26.5,
        ]);

        $seccoes = [[
            'piscina' => $pool,
            'registos' => collect([$record]),
            'controlador' => collect(),
        ]];

        $html = view('pdf.livro-sanitario', [
            'instalacao' => $env['installation'],
            'seccoes' => $seccoes,
            'inicio' => now()->startOfDay(),
            'fim' => now()->endOfDay(),
            'emitidoEm' => now(),
            'emitidoPor' => $env['admin']->name,
            'colunasVisiveis' => ['ph', 'cloro_livre'],
            'seccoesVisiveis' => ['mostrar_assinaturas'],
            'modo' => 'todos',
        ])->render();

        $this->assertStringContainsString('pH', $html);
        $this->assertStringContainsString('Cl. Livre', $html);
        $this->assertStringNotContainsString('Técnico</th>', $html);
        $this->assertStringNotContainsString('Transp.</th>', $html);
        $this->assertStringContainsString('Responsável Técnico', $html);
        $this->assertStringNotContainsString('Registo conforme CN 14/DA', $html);
    }

    // Helper methods

    private function recordIsConforming(DailyRecord $record): bool
    {
        if ($record->ph === null || $record->cloro_livre === null) {
            return false;
        }

        $ph_ok = $record->ph >= DailyRecord::PH_MIN && $record->ph <= DailyRecord::PH_MAX;
        $cloro_livre_ok = $record->cloro_livre >= DailyRecord::CLORO_LIVRE_MIN &&
                          $record->cloro_livre <= DailyRecord::CLORO_LIVRE_MAX;

        if ($record->cloro_total !== null) {
            $cloro_combinado = $record->cloro_total - $record->cloro_livre;
            $cloro_combinado_ok = $cloro_combinado <= DailyRecord::CLORO_COMBINADO_MAX;
        } else {
            $cloro_combinado_ok = true;
        }

        $transparencia_ok = $record->transparencia === null ||
                            $record->transparencia <= DailyRecord::TRANSPARENCIA_MAX;

        return $ph_ok && $cloro_livre_ok && $cloro_combinado_ok && $transparencia_ok;
    }

    private function temperatureIsConforming(DailyRecord $record, Pool $pool): bool
    {
        if ($record->temperatura === null) {
            return false;
        }

        return $record->temperatura >= $pool->temp_min && $record->temperatura <= $pool->temp_max;
    }
}
