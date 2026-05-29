<?php

namespace Database\Seeders;

use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoRegistosDiariosSeeder extends Seeder
{
    public function run(): void
    {
        // Limpa registos existentes para evitar duplicados.
        DailyRecord::query()->delete();

        $tecnico = User::where('email', 'tecnico@mmcrespo.pt')->first()
            ?? User::find(2);
        $ns = User::where('email', 'ns@mmcrespo.pt')->first()
            ?? User::find(3);

        $pools = Pool::all()->keyBy('id');

        // Valores-base por piscina (realistas mas com variação)
        // Competição: temp 26-27°C, cloro 0.8-1.5, pH 7.2-7.6
        // Lazer/Infantil: temp 28-30°C
        // Maceira/Caranguejeira: externas, mais variação
        $config = [
            1 => ['temp_base' => 26.5, 'temp_var' => 0.4, 'cloro_base' => 1.1, 'cloro_var' => 0.3, 'ph_base' => 7.4, 'ph_var' => 0.2, 'autor' => $tecnico],
            2 => ['temp_base' => 29.0, 'temp_var' => 0.5, 'cloro_base' => 1.2, 'cloro_var' => 0.4, 'ph_base' => 7.3, 'ph_var' => 0.2, 'autor' => $tecnico],
            3 => ['temp_base' => 29.5, 'temp_var' => 0.3, 'cloro_base' => 1.0, 'cloro_var' => 0.3, 'ph_base' => 7.5, 'ph_var' => 0.15, 'autor' => $ns],
            4 => ['temp_base' => 28.5, 'temp_var' => 0.8, 'cloro_base' => 1.3, 'cloro_var' => 0.5, 'ph_base' => 7.4, 'ph_var' => 0.25, 'autor' => $ns],
            5 => ['temp_base' => 28.0, 'temp_var' => 0.9, 'cloro_base' => 1.1, 'cloro_var' => 0.4, 'ph_base' => 7.5, 'ph_var' => 0.2, 'autor' => $ns],
        ];

        // Simula variação ao longo de 14 dias
        // Dia 3: piscina Competição tem pH alto (8.2 — incidente real)
        // Dia 7: Maceira tem cloro baixo (0.3 — fora de limite, corrigido no dia seguinte)
        // Restantes dias: variação normal dentro dos limites
        $anomalias = [
            ['pool_id' => 1, 'dia' => 3,  'ph' => 8.2,  'cloro_livre' => 0.9],  // pH alto — alerta vermelho
            ['pool_id' => 4, 'dia' => 7,  'cloro_livre' => 0.3, 'cloro_total' => 0.4], // Cloro baixo — alerta
            ['pool_id' => 5, 'dia' => 10, 'temp' => 25.0],                        // Temp baixa
        ];

        $registos = [];
        $anomaliaMap = collect($anomalias)->groupBy(fn($a) => "{$a['pool_id']}-{$a['dia']}");

        foreach (range(13, 0) as $diasAtras) {
            $data = Carbon::today()->subDays($diasAtras)->setTime(9, rand(0, 30));

            foreach ($config as $poolId => $cfg) {
                // Fins de semana: 80% de probabilidade de ter registo
                // Dias de semana: 95%
                $diaSemana = $data->dayOfWeek;
                $prob = ($diaSemana === 0 || $diaSemana === 6) ? 80 : 95;
                if (rand(1, 100) > $prob) {
                    continue;
                }

                $chave = "{$poolId}-" . (13 - $diasAtras + 1);
                $anomalia = $anomaliaMap->get($chave)?->first();

                $cloroLivre = $anomalia['cloro_livre'] ?? round($cfg['cloro_base'] + (rand(-100, 100) / 100) * $cfg['cloro_var'], 2);
                $cloroTotal = $anomalia['cloro_total'] ?? round($cloroLivre + (rand(10, 40) / 100), 2);
                $ph = $anomalia['ph'] ?? round($cfg['ph_base'] + (rand(-100, 100) / 100) * $cfg['ph_var'], 2);
                $temp = $anomalia['temp'] ?? round($cfg['temp_base'] + (rand(-100, 100) / 100) * $cfg['temp_var'], 1);
                $transparencia = rand(2, 3);

                $registos[] = [
                    'pool_id'      => $poolId,
                    'user_id'      => $cfg['autor']?->id ?? 2,
                    'registado_em' => $data->copy()->setTime(9, rand(0, 45)),
                    'cloro_livre'  => max(0.1, $cloroLivre),
                    'cloro_total'  => max(0.2, $cloroTotal),
                    'ph'           => $ph,
                    'temperatura'  => $temp,
                    'transparencia'=> $transparencia,
                    'caleira_feita'=> rand(0, 1),
                    'renovacao_agua' => rand(0, 10) > 8,
                    'e_correcao'   => false,
                    'created_at'   => $data,
                    'updated_at'   => $data,
                ];
            }
        }

        // Insere em bulk
        collect($registos)->chunk(50)->each(fn ($chunk) => DailyRecord::insert($chunk->toArray()));

        $total = DailyRecord::count();
        $this->command->info("Inseridos {$total} registos de demonstração.");

        // Cria um registo de correção para demonstrar o workflow
        $registoAnomaloPH = DailyRecord::where('pool_id', 1)
            ->where('ph', '>', 8.0)
            ->whereDoesntHave('correcoes')
            ->first();

        if ($registoAnomaloPH) {
            DailyRecord::create([
                'pool_id'            => $registoAnomaloPH->pool_id,
                'user_id'            => $tecnico?->id ?? 2,
                'registado_em'       => $registoAnomaloPH->registado_em->addHours(2),
                'cloro_livre'        => $registoAnomaloPH->cloro_livre,
                'cloro_total'        => $registoAnomaloPH->cloro_total,
                'ph'                 => 7.4,
                'temperatura'        => $registoAnomaloPH->temperatura,
                'transparencia'      => $registoAnomaloPH->transparencia,
                'caleira_feita'      => $registoAnomaloPH->caleira_feita,
                'renovacao_agua'     => false,
                'e_correcao'         => true,
                'corrige_registo_id' => $registoAnomaloPH->id,
                'razao_correcao'     => 'pH lido incorretamente — sonda descalibrada. Valor real: 7,4.',
            ]);
            $this->command->info("Criado registo de correção para demonstração do workflow.");
        }
    }
}
