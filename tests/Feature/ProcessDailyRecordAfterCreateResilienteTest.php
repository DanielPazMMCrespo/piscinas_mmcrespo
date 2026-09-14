<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Jobs\ProcessDailyRecordAfterCreate;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\RecordAddition;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\TapAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * 2026-09: o Resend recusou os e-mails de não-conformidade (domínio por
 * verificar) e a excepção subiu de `notificarNaoConformidade()` para o job.
 * As 3 tentativas repetiram tudo o que corre antes — stock debitado 3x,
 * fotos duplicadas — e nunca chegaram ao `gerirTorneira()`, que corria
 * depois. O admin só via "Processamento do registo diário falhou".
 */
class ProcessDailyRecordAfterCreateResilienteTest extends TestCase
{
    use RefreshDatabase;

    private Pool $piscina;

    private Product $produto;

    private StockInstallation $stock;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        // Fora da janela de silêncio (22:00-08:00 e domingos), senão o canal
        // 'mail' nem chega a ser escolhido e o teste não testa nada.
        $this->travelTo(Carbon::parse('2026-09-15 10:00:00'));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $this->autor = User::factory()->create();
        $this->autor->assignRole(UserRole::TECNICO);

        $instalacao = Installation::factory()->create();
        $this->piscina = Pool::factory()->create(['installation_id' => $instalacao->id]);
        $this->produto = Product::factory()->create(['unidade' => 'L']);

        $this->stock = StockInstallation::create([
            'installation_id' => $instalacao->id,
            'product_id' => $this->produto->id,
            'quantity' => 10.000,
            'limite_minimo' => 0,
        ]);
    }

    public function test_o_email_recusado_nao_impede_stock_torneira_nem_o_sino(): void
    {
        // O canal 'mail' atira como o Resend atira quando o domínio não está
        // verificado — depois do canal 'database', que o via() põe primeiro.
        Notification::extend('mail', fn () => new class
        {
            public function send(mixed $notifiable, mixed $notification): void
            {
                throw new RuntimeException('Resend recusou o destinatário');
            }
        });

        $registo = $this->registoNaoConforme(['analises_fotos' => ['analises/a.jpg']]);

        (new ProcessDailyRecordAfterCreate($registo->id, $this->autor->id))->handle();

        $this->assertSame(8.0, (float) $this->stock->fresh()->quantity);
        $this->assertDatabaseHas('tap_alerts', ['pool_id' => $this->piscina->id, 'resolved_at' => null]);
        $this->assertDatabaseCount('record_photos', 1);

        // O canal 'database' corre antes do 'mail': o sino do admin fica
        // escrito à mesma. Também prova que o teste não passou em vazio por
        // não haver violação nenhuma a notificar.
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_correr_o_job_duas_vezes_nao_duplica_stock_nem_fotos(): void
    {
        $registo = $this->registoNaoConforme(['analises_fotos' => ['analises/a.jpg']]);

        $job = new ProcessDailyRecordAfterCreate($registo->id, $this->autor->id);
        $job->handle();
        $job->handle();

        $this->assertSame(8.0, (float) $this->stock->fresh()->quantity);
        $this->assertSame(1, StockInstallationLog::query()->where('tipo_movimento', 'consumo')->count());
        $this->assertDatabaseCount('record_photos', 1);
        $this->assertSame(1, TapAlert::query()->where('pool_id', $this->piscina->id)->count());
    }

    /** @param array<string, mixed> $extra */
    private function registoNaoConforme(array $extra = []): DailyRecord
    {
        $registo = DailyRecord::create(array_merge([
            'pool_id' => $this->piscina->id,
            'user_id' => $this->autor->id,
            'registado_em' => now(),
            'ph' => 9.9,
            'cloro_livre' => 0.1,
            'agua_modo' => 'on_com_agua',
        ], $extra));

        RecordAddition::factory()->create([
            'daily_record_id' => $registo->id,
            'product_id' => $this->produto->id,
            'quantity' => 2.000,
        ]);

        return $registo;
    }
}
