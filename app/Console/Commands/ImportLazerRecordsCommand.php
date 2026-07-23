<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportLazerRecordsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:lazer-records';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa registos diários da piscina de lazer para a aplicação';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('A iniciar importação de registos diários da piscina de Lazer (Pool ID: 2)...');

        // Procurar o utilizador técnico ou primeiro utilizador
        $user = User::whereHas('roles', fn ($q) => $q->where('name', 'tecnico'))->first()
            ?? User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first()
            ?? User::first();

        if (! $user) {
            $this->error('Erro: Nenhum utilizador encontrado na base de dados para associar os registos.');

            return 1;
        }

        $this->info("Registos serão associados ao utilizador: {$user->name} (ID: {$user->id})");

        $records = [
            // --- Screenshot 1 (Rows 2 to 60) ---
            ['data' => '30/04/2026', 'hora' => '12:00', 'ph' => 7.47, 'temp' => 29.3, 'cloro_livre' => 0.67, 'cloro_total' => 0.89, 'obs' => null],
            ['data' => '30/04/2026', 'hora' => '16:00', 'ph' => 6.83, 'temp' => 29.4, 'cloro_livre' => 0.03, 'cloro_total' => 0.34, 'obs' => null],
            ['data' => '30/04/2026', 'hora' => '22:00', 'ph' => 7.65, 'temp' => 29.5, 'cloro_livre' => 0.92, 'cloro_total' => 1.60, 'obs' => null],
            ['data' => '02/05/2026', 'hora' => '08:00', 'ph' => null, 'temp' => null, 'cloro_livre' => null, 'cloro_total' => null, 'obs' => '* SEM CLORO'],
            ['data' => '02/05/2026', 'hora' => '12:00', 'ph' => null, 'temp' => null, 'cloro_livre' => null, 'cloro_total' => null, 'obs' => null],
            ['data' => '02/05/2026', 'hora' => '16:00', 'ph' => null, 'temp' => null, 'cloro_livre' => null, 'cloro_total' => null, 'obs' => null],
            ['data' => '04/05/2026', 'hora' => '08:00', 'ph' => null, 'temp' => 30.0, 'cloro_livre' => null, 'cloro_total' => null, 'obs' => null],
            ['data' => '04/05/2026', 'hora' => '12:00', 'ph' => 7.31, 'temp' => 29.9, 'cloro_livre' => 0.77, 'cloro_total' => 1.69, 'obs' => null],
            ['data' => '04/05/2026', 'hora' => '16:00', 'ph' => 7.40, 'temp' => 29.8, 'cloro_livre' => 0.98, 'cloro_total' => 1.20, 'obs' => null],
            ['data' => '04/05/2026', 'hora' => '22:00', 'ph' => 7.33, 'temp' => 29.3, 'cloro_livre' => 0.61, 'cloro_total' => 1.02, 'obs' => null],
            ['data' => '05/05/2026', 'hora' => '08:00', 'ph' => 7.41, 'temp' => 29.6, 'cloro_livre' => 1.00, 'cloro_total' => 1.37, 'obs' => null],
            ['data' => '05/05/2026', 'hora' => '12:00', 'ph' => 7.32, 'temp' => 29.8, 'cloro_livre' => 0.82, 'cloro_total' => 0.71, 'obs' => null],
            ['data' => '05/05/2026', 'hora' => '16:00', 'ph' => 7.42, 'temp' => 29.5, 'cloro_livre' => 2.24, 'cloro_total' => 2.74, 'obs' => null],
            ['data' => '05/05/2026', 'hora' => '22:00', 'ph' => 7.35, 'temp' => 29.6, 'cloro_livre' => 1.30, 'cloro_total' => 1.90, 'obs' => null],
            ['data' => '06/05/2026', 'hora' => '08:00', 'ph' => 6.88, 'temp' => 29.5, 'cloro_livre' => 0.71, 'cloro_total' => 0.53, 'obs' => null],
            ['data' => '06/05/2026', 'hora' => '12:00', 'ph' => 7.31, 'temp' => 29.4, 'cloro_livre' => 0.99, 'cloro_total' => 1.29, 'obs' => null],
            ['data' => '06/05/2026', 'hora' => '16:00', 'ph' => 7.30, 'temp' => 29.0, 'cloro_livre' => 0.45, 'cloro_total' => 0.90, 'obs' => null],
            ['data' => '06/05/2026', 'hora' => '22:00', 'ph' => 7.46, 'temp' => 29.3, 'cloro_livre' => 0.14, 'cloro_total' => 0.73, 'obs' => null],
            ['data' => '07/05/2026', 'hora' => '08:00', 'ph' => 7.88, 'temp' => 28.4, 'cloro_livre' => null, 'cloro_total' => null, 'obs' => null],
            ['data' => '07/05/2026', 'hora' => '12:00', 'ph' => 7.33, 'temp' => 28.6, 'cloro_livre' => 0.91, 'cloro_total' => 0.62, 'obs' => null],
            ['data' => '07/05/2026', 'hora' => '16:00', 'ph' => 7.67, 'temp' => 28.7, 'cloro_livre' => 1.26, 'cloro_total' => 1.54, 'obs' => null],
            ['data' => '07/05/2026', 'hora' => '22:00', 'ph' => 7.54, 'temp' => 29.0, 'cloro_livre' => 0.65, 'cloro_total' => 1.27, 'obs' => null],
            ['data' => '08/05/2026', 'hora' => '08:00', 'ph' => 7.42, 'temp' => 29.2, 'cloro_livre' => 2.22, 'cloro_total' => 2.79, 'obs' => null],
            ['data' => '08/05/2026', 'hora' => '12:00', 'ph' => 7.30, 'temp' => 29.1, 'cloro_livre' => 2.00, 'cloro_total' => 2.51, 'obs' => null],
            ['data' => '08/05/2026', 'hora' => '16:00', 'ph' => 7.47, 'temp' => 29.2, 'cloro_livre' => 1.40, 'cloro_total' => 2.12, 'obs' => null],
            ['data' => '08/05/2026', 'hora' => '22:00', 'ph' => 7.56, 'temp' => 29.2, 'cloro_livre' => 1.85, 'cloro_total' => 2.33, 'obs' => null],
            ['data' => '09/05/2026', 'hora' => '08:00', 'ph' => 7.39, 'temp' => 28.8, 'cloro_livre' => 2.33, 'cloro_total' => 2.78, 'obs' => null],
            ['data' => '09/05/2026', 'hora' => '12:00', 'ph' => 7.33, 'temp' => 28.5, 'cloro_livre' => 2.09, 'cloro_total' => 2.51, 'obs' => null],
            ['data' => '09/05/2026', 'hora' => '16:00', 'ph' => 7.38, 'temp' => 28.4, 'cloro_livre' => 2.00, 'cloro_total' => 2.50, 'obs' => null],
            ['data' => '11/05/2026', 'hora' => '08:00', 'ph' => 7.46, 'temp' => 28.7, 'cloro_livre' => 2.47, 'cloro_total' => 3.16, 'obs' => null],
            ['data' => '11/05/2026', 'hora' => '12:00', 'ph' => 7.40, 'temp' => 28.3, 'cloro_livre' => 2.70, 'cloro_total' => 2.00, 'obs' => null],
            ['data' => '11/05/2026', 'hora' => '16:00', 'ph' => 6.78, 'temp' => 28.6, 'cloro_livre' => 0.12, 'cloro_total' => 0.92, 'obs' => null],
            ['data' => '11/05/2026', 'hora' => '22:00', 'ph' => 7.41, 'temp' => 28.6, 'cloro_livre' => 0.34, 'cloro_total' => 0.76, 'obs' => null],
            ['data' => '12/05/2026', 'hora' => '08:00', 'ph' => 7.80, 'temp' => 28.8, 'cloro_livre' => 3.51, 'cloro_total' => 3.80, 'obs' => null],
            ['data' => '12/05/2026', 'hora' => '12:00', 'ph' => 7.41, 'temp' => 29.0, 'cloro_livre' => 2.52, 'cloro_total' => 2.73, 'obs' => null],
            ['data' => '12/05/2026', 'hora' => '16:00', 'ph' => 7.57, 'temp' => 29.1, 'cloro_livre' => 1.44, 'cloro_total' => 2.03, 'obs' => null],
            ['data' => '12/05/2026', 'hora' => '22:00', 'ph' => 7.58, 'temp' => 29.2, 'cloro_livre' => 0.98, 'cloro_total' => 2.10, 'obs' => null],
            ['data' => '13/05/2026', 'hora' => '08:00', 'ph' => 7.31, 'temp' => 29.2, 'cloro_livre' => 0.76, 'cloro_total' => 1.31, 'obs' => null],
            ['data' => '13/05/2026', 'hora' => '12:00', 'ph' => 7.21, 'temp' => 29.1, 'cloro_livre' => 0.66, 'cloro_total' => 0.99, 'obs' => null],
            ['data' => '13/05/2026', 'hora' => '16:00', 'ph' => 7.33, 'temp' => 29.1, 'cloro_livre' => 0.05, 'cloro_total' => 0.38, 'obs' => null],
            ['data' => '13/05/2026', 'hora' => '22:00', 'ph' => 7.01, 'temp' => 29.3, 'cloro_livre' => 0.02, 'cloro_total' => 0.06, 'obs' => null],
            ['data' => '14/05/2026', 'hora' => '08:00', 'ph' => 7.38, 'temp' => 29.3, 'cloro_livre' => 0.14, 'cloro_total' => 1.22, 'obs' => null],
            ['data' => '14/05/2026', 'hora' => '12:00', 'ph' => 7.21, 'temp' => 29.2, 'cloro_livre' => 0.10, 'cloro_total' => 0.78, 'obs' => null],
            ['data' => '14/05/2026', 'hora' => '16:00', 'ph' => 7.60, 'temp' => 29.5, 'cloro_livre' => 0.05, 'cloro_total' => 0.28, 'obs' => null],
            ['data' => '14/05/2026', 'hora' => '22:00', 'ph' => 7.23, 'temp' => 29.5, 'cloro_livre' => 5.30, 'cloro_total' => null, 'obs' => 'Sem leitura'],
            ['data' => '15/05/2026', 'hora' => '08:00', 'ph' => 7.99, 'temp' => 29.6, 'cloro_livre' => 5.84, 'cloro_total' => 6.31, 'obs' => null],
            ['data' => '15/05/2026', 'hora' => '12:00', 'ph' => 7.62, 'temp' => 29.4, 'cloro_livre' => 5.44, 'cloro_total' => 5.96, 'obs' => null],
            ['data' => '15/05/2026', 'hora' => '16:00', 'ph' => 7.36, 'temp' => 29.5, 'cloro_livre' => 4.63, 'cloro_total' => 5.09, 'obs' => null],
            ['data' => '15/05/2026', 'hora' => '22:00', 'ph' => 7.55, 'temp' => 29.4, 'cloro_livre' => 4.32, 'cloro_total' => 4.60, 'obs' => null],
            ['data' => '16/05/2026', 'hora' => '08:00', 'ph' => 7.27, 'temp' => 29.8, 'cloro_livre' => 2.81, 'cloro_total' => 3.82, 'obs' => null],
            ['data' => '16/05/2026', 'hora' => '12:00', 'ph' => 7.89, 'temp' => 28.6, 'cloro_livre' => 2.71, 'cloro_total' => 3.79, 'obs' => null],
            ['data' => '16/05/2026', 'hora' => '16:00', 'ph' => 7.79, 'temp' => 28.5, 'cloro_livre' => 2.30, 'cloro_total' => 3.32, 'obs' => null],
            ['data' => '18/05/2026', 'hora' => '08:00', 'ph' => 7.42, 'temp' => 29.0, 'cloro_livre' => 2.93, 'cloro_total' => 3.17, 'obs' => null],
            ['data' => '18/05/2026', 'hora' => '12:00', 'ph' => 7.39, 'temp' => 29.0, 'cloro_livre' => 2.30, 'cloro_total' => 2.90, 'obs' => null],
            ['data' => '18/05/2026', 'hora' => '16:00', 'ph' => 7.50, 'temp' => 29.1, 'cloro_livre' => 1.29, 'cloro_total' => 1.79, 'obs' => null],
            ['data' => '18/05/2026', 'hora' => '22:00', 'ph' => 6.94, 'temp' => 29.1, 'cloro_livre' => 0.09, 'cloro_total' => 0.89, 'obs' => null],
            ['data' => '19/05/2026', 'hora' => '08:00', 'ph' => 7.39, 'temp' => 29.1, 'cloro_livre' => 1.33, 'cloro_total' => 1.99, 'obs' => null],
            ['data' => '19/05/2026', 'hora' => '12:00', 'ph' => 7.22, 'temp' => 29.0, 'cloro_livre' => 1.00, 'cloro_total' => 1.89, 'obs' => null],
            ['data' => '19/05/2026', 'hora' => '16:00', 'ph' => 6.65, 'temp' => 29.2, 'cloro_livre' => 0.67, 'cloro_total' => 0.90, 'obs' => null],

            // --- Screenshot 2 (Rows 61 to 117) ---
            ['data' => '19/05/2026', 'hora' => '22:00', 'ph' => 7.02, 'temp' => 29.2, 'cloro_livre' => 0.00, 'cloro_total' => 0.10, 'obs' => null],
            ['data' => '20/05/2026', 'hora' => '08:00', 'ph' => 7.00, 'temp' => 29.4, 'cloro_livre' => 0.02, 'cloro_total' => 0.95, 'obs' => null],
            ['data' => '20/05/2026', 'hora' => '12:00', 'ph' => 7.51, 'temp' => 29.3, 'cloro_livre' => 1.89, 'cloro_total' => 2.75, 'obs' => null],
            ['data' => '20/05/2026', 'hora' => '16:00', 'ph' => 7.29, 'temp' => 29.2, 'cloro_livre' => 1.59, 'cloro_total' => 1.87, 'obs' => null],
            ['data' => '20/05/2026', 'hora' => '22:00', 'ph' => 7.09, 'temp' => 29.2, 'cloro_livre' => 0.68, 'cloro_total' => 1.30, 'obs' => null],
            ['data' => '21/05/2026', 'hora' => '08:00', 'ph' => null, 'temp' => 29.1, 'cloro_livre' => 0.99, 'cloro_total' => 1.31, 'obs' => null],
            ['data' => '21/05/2026', 'hora' => '12:00', 'ph' => 7.43, 'temp' => 29.0, 'cloro_livre' => 4.18, 'cloro_total' => 4.77, 'obs' => null],
            ['data' => '21/05/2026', 'hora' => '16:00', 'ph' => 7.53, 'temp' => 29.1, 'cloro_livre' => 2.94, 'cloro_total' => 3.55, 'obs' => null],
            ['data' => '21/05/2026', 'hora' => '22:00', 'ph' => 7.50, 'temp' => 29.2, 'cloro_livre' => 2.16, 'cloro_total' => 2.95, 'obs' => null],
            ['data' => '23/05/2026', 'hora' => '08:00', 'ph' => 7.32, 'temp' => 28.3, 'cloro_livre' => 4.85, 'cloro_total' => 5.21, 'obs' => null],
            ['data' => '23/05/2026', 'hora' => '12:00', 'ph' => 7.55, 'temp' => 28.5, 'cloro_livre' => 4.08, 'cloro_total' => 4.76, 'obs' => null],
            ['data' => '23/05/2026', 'hora' => '16:00', 'ph' => 7.59, 'temp' => 29.4, 'cloro_livre' => 4.08, 'cloro_total' => 5.03, 'obs' => null],
            ['data' => '25/05/2026', 'hora' => '12:00', 'ph' => 7.22, 'temp' => 29.3, 'cloro_livre' => 3.41, 'cloro_total' => 3.87, 'obs' => null],
            ['data' => '25/05/2026', 'hora' => '16:00', 'ph' => 7.33, 'temp' => 29.4, 'cloro_livre' => 1.96, 'cloro_total' => 2.28, 'obs' => null],
            ['data' => '25/05/2026', 'hora' => '22:00', 'ph' => 6.81, 'temp' => 29.4, 'cloro_livre' => 0.64, 'cloro_total' => 1.47, 'obs' => null],
            ['data' => '26/05/2026', 'hora' => '08:00', 'ph' => 7.15, 'temp' => 29.6, 'cloro_livre' => 0.38, 'cloro_total' => 0.57, 'obs' => null],
            ['data' => '26/05/2026', 'hora' => '12:00', 'ph' => 7.27, 'temp' => 29.5, 'cloro_livre' => 0.76, 'cloro_total' => 1.10, 'obs' => null],
            ['data' => '26/05/2026', 'hora' => '16:00', 'ph' => 7.35, 'temp' => 29.9, 'cloro_livre' => 0.70, 'cloro_total' => 1.19, 'obs' => null],
            ['data' => '26/05/2026', 'hora' => '22:00', 'ph' => 7.20, 'temp' => 29.2, 'cloro_livre' => 0.35, 'cloro_total' => 0.91, 'obs' => null],
            ['data' => '27/05/2026', 'hora' => '08:00', 'ph' => 7.21, 'temp' => 29.4, 'cloro_livre' => 0.38, 'cloro_total' => 0.59, 'obs' => null],
            ['data' => '27/05/2026', 'hora' => '12:00', 'ph' => 7.30, 'temp' => 29.3, 'cloro_livre' => 1.57, 'cloro_total' => 1.75, 'obs' => null],
            ['data' => '27/05/2026', 'hora' => '16:00', 'ph' => 7.27, 'temp' => 30.0, 'cloro_livre' => 0.14, 'cloro_total' => 0.65, 'obs' => null],
            ['data' => '27/05/2026', 'hora' => '22:00', 'ph' => 7.20, 'temp' => 29.8, 'cloro_livre' => 0.82, 'cloro_total' => 1.37, 'obs' => null],
            ['data' => '28/05/2026', 'hora' => '08:00', 'ph' => null, 'temp' => 29.0, 'cloro_livre' => 1.12, 'cloro_total' => 1.39, 'obs' => null],
            ['data' => '28/05/2026', 'hora' => '12:00', 'ph' => null, 'temp' => 29.1, 'cloro_livre' => 0.81, 'cloro_total' => 1.15, 'obs' => null],
            ['data' => '28/05/2026', 'hora' => '16:00', 'ph' => null, 'temp' => 28.8, 'cloro_livre' => 1.11, 'cloro_total' => 1.45, 'obs' => null],
            ['data' => '28/05/2026', 'hora' => '22:00', 'ph' => null, 'temp' => 29.2, 'cloro_livre' => 0.39, 'cloro_total' => 0.82, 'obs' => null],
            ['data' => '29/05/2026', 'hora' => '08:00', 'ph' => null, 'temp' => 29.5, 'cloro_livre' => 1.09, 'cloro_total' => 1.61, 'obs' => null],
            ['data' => '29/05/2026', 'hora' => '12:00', 'ph' => null, 'temp' => 29.4, 'cloro_livre' => 1.98, 'cloro_total' => 2.30, 'obs' => null],
            ['data' => '29/05/2026', 'hora' => '16:00', 'ph' => 7.43, 'temp' => 30.0, 'cloro_livre' => 1.23, 'cloro_total' => 1.75, 'obs' => null],
            ['data' => '29/05/2026', 'hora' => '22:00', 'ph' => null, 'temp' => 29.7, 'cloro_livre' => 0.58, 'cloro_total' => 1.05, 'obs' => null],
            ['data' => '30/05/2026', 'hora' => '08:00', 'ph' => 7.27, 'temp' => 32.1, 'cloro_livre' => 0.30, 'cloro_total' => 0.72, 'obs' => null],
            ['data' => '30/05/2026', 'hora' => '12:00', 'ph' => 7.25, 'temp' => 32.1, 'cloro_livre' => 0.46, 'cloro_total' => 0.80, 'obs' => null],
            ['data' => '30/05/2026', 'hora' => '16:00', 'ph' => 6.79, 'temp' => 31.9, 'cloro_livre' => 0.87, 'cloro_total' => 0.93, 'obs' => null],
            ['data' => '01/06/2026', 'hora' => '08:00', 'ph' => 7.71, 'temp' => 28.4, 'cloro_livre' => 2.74, 'cloro_total' => 3.19, 'obs' => null],
            ['data' => '01/06/2026', 'hora' => '12:00', 'ph' => 7.97, 'temp' => 28.3, 'cloro_livre' => 1.66, 'cloro_total' => 1.87, 'obs' => null],
            ['data' => '01/06/2026', 'hora' => '16:00', 'ph' => 7.58, 'temp' => 28.3, 'cloro_livre' => 2.24, 'cloro_total' => 2.71, 'obs' => null],
            ['data' => '01/06/2026', 'hora' => '22:00', 'ph' => 7.45, 'temp' => 28.3, 'cloro_livre' => 1.52, 'cloro_total' => 2.08, 'obs' => null],
            ['data' => '02/06/2026', 'hora' => '08:00', 'ph' => 7.21, 'temp' => 28.8, 'cloro_livre' => 0.86, 'cloro_total' => 1.20, 'obs' => null],
            ['data' => '02/06/2026', 'hora' => '12:00', 'ph' => 7.17, 'temp' => 28.7, 'cloro_livre' => 0.66, 'cloro_total' => 0.99, 'obs' => null],
            ['data' => '02/06/2026', 'hora' => '16:00', 'ph' => 6.77, 'temp' => 28.8, 'cloro_livre' => 0.50, 'cloro_total' => 0.93, 'obs' => null],
            ['data' => '02/06/2026', 'hora' => '22:00', 'ph' => 7.44, 'temp' => 28.8, 'cloro_livre' => 1.34, 'cloro_total' => 1.90, 'obs' => null],
            ['data' => '03/06/2026', 'hora' => '08:00', 'ph' => 7.28, 'temp' => 28.5, 'cloro_livre' => 0.89, 'cloro_total' => null, 'obs' => null],
            ['data' => '03/06/2026', 'hora' => '12:00', 'ph' => 7.63, 'temp' => 28.2, 'cloro_livre' => 4.25, 'cloro_total' => null, 'obs' => null],
            ['data' => '03/06/2026', 'hora' => '16:00', 'ph' => 7.22, 'temp' => 28.8, 'cloro_livre' => 1.75, 'cloro_total' => null, 'obs' => null],
            ['data' => '03/06/2026', 'hora' => '22:00', 'ph' => 7.00, 'temp' => 28.8, 'cloro_livre' => 2.10, 'cloro_total' => null, 'obs' => null],
            ['data' => '05/06/2026', 'hora' => '08:00', 'ph' => 7.38, 'temp' => 27.5, 'cloro_livre' => 0.82, 'cloro_total' => 0.90, 'obs' => null],
            ['data' => '05/06/2026', 'hora' => '12:00', 'ph' => 7.32, 'temp' => 27.5, 'cloro_livre' => 2.19, 'cloro_total' => null, 'obs' => null],
            ['data' => '05/06/2026', 'hora' => '16:00', 'ph' => 7.27, 'temp' => 27.8, 'cloro_livre' => 2.34, 'cloro_total' => 3.19, 'obs' => null],
            ['data' => '05/06/2026', 'hora' => '22:00', 'ph' => null, 'temp' => 28.0, 'cloro_livre' => 1.90, 'cloro_total' => null, 'obs' => null],
            ['data' => '06/06/2026', 'hora' => '08:00', 'ph' => null, 'temp' => 27.4, 'cloro_livre' => 0.73, 'cloro_total' => null, 'obs' => null],
            ['data' => '06/06/2026', 'hora' => '12:00', 'ph' => null, 'temp' => 27.7, 'cloro_livre' => 0.30, 'cloro_total' => null, 'obs' => null],
            ['data' => '06/06/2026', 'hora' => '15:30', 'ph' => null, 'temp' => 27.7, 'cloro_livre' => 0.05, 'cloro_total' => null, 'obs' => null],
            ['data' => '08/06/2026', 'hora' => '08:00', 'ph' => 7.50, 'temp' => 27.7, 'cloro_livre' => 0.32, 'cloro_total' => 0.47, 'obs' => null],
            ['data' => '08/06/2026', 'hora' => '12:00', 'ph' => 7.30, 'temp' => 27.7, 'cloro_livre' => 1.54, 'cloro_total' => 1.60, 'obs' => null],
            ['data' => '08/06/2026', 'hora' => '16:00', 'ph' => 7.40, 'temp' => 28.0, 'cloro_livre' => 1.25, 'cloro_total' => 1.39, 'obs' => null],
            ['data' => '08/06/2026', 'hora' => '22:00', 'ph' => 7.40, 'temp' => 28.2, 'cloro_livre' => 1.58, 'cloro_total' => 1.63, 'obs' => null],

            // --- Screenshot 3 (Rows 118 to 164) ---
            ['data' => '09/06/2026', 'hora' => '08:00', 'ph' => 7.39, 'temp' => 28.4, 'cloro_livre' => 0.71, 'cloro_total' => 0.56, 'obs' => null],
            ['data' => '09/06/2026', 'hora' => '12:00', 'ph' => 7.30, 'temp' => 28.4, 'cloro_livre' => 0.73, 'cloro_total' => 0.79, 'obs' => null],
            ['data' => '09/06/2026', 'hora' => '16:00', 'ph' => 7.60, 'temp' => 28.6, 'cloro_livre' => 1.37, 'cloro_total' => 1.63, 'obs' => null],
            ['data' => '09/06/2026', 'hora' => '22:00', 'ph' => 7.50, 'temp' => 28.8, 'cloro_livre' => 0.47, 'cloro_total' => 1.05, 'obs' => null],
            ['data' => '11/06/2026', 'hora' => '08:00', 'ph' => 7.20, 'temp' => 28.8, 'cloro_livre' => 0.26, 'cloro_total' => 0.51, 'obs' => null],
            ['data' => '11/06/2026', 'hora' => '12:00', 'ph' => null, 'temp' => 28.9, 'cloro_livre' => 0.39, 'cloro_total' => 0.67, 'obs' => null],
            ['data' => '11/06/2026', 'hora' => '16:00', 'ph' => 7.30, 'temp' => 28.9, 'cloro_livre' => 2.06, 'cloro_total' => 2.11, 'obs' => null],
            ['data' => '11/06/2026', 'hora' => '22:00', 'ph' => 7.60, 'temp' => 28.9, 'cloro_livre' => 2.14, 'cloro_total' => 2.61, 'obs' => null],
            ['data' => '12/06/2026', 'hora' => '08:00', 'ph' => 7.20, 'temp' => 29.2, 'cloro_livre' => 0.59, 'cloro_total' => 0.74, 'obs' => null],
            ['data' => '12/06/2026', 'hora' => '12:00', 'ph' => 7.00, 'temp' => 29.1, 'cloro_livre' => 0.64, 'cloro_total' => 0.66, 'obs' => null],
            ['data' => '12/06/2026', 'hora' => '16:00', 'ph' => 7.40, 'temp' => 29.1, 'cloro_livre' => 1.34, 'cloro_total' => 1.60, 'obs' => null],
            ['data' => '12/06/2026', 'hora' => '22:00', 'ph' => 7.40, 'temp' => 29.1, 'cloro_livre' => 1.51, 'cloro_total' => 2.05, 'obs' => null],
            ['data' => '15/06/2026', 'hora' => '08:00', 'ph' => 7.20, 'temp' => 28.6, 'cloro_livre' => 0.80, 'cloro_total' => 0.92, 'obs' => null],
            ['data' => '15/06/2026', 'hora' => '12:00', 'ph' => 7.10, 'temp' => 28.9, 'cloro_livre' => 1.51, 'cloro_total' => 1.56, 'obs' => null],
            ['data' => '16/06/2026', 'hora' => '08:00', 'ph' => 7.00, 'temp' => 28.9, 'cloro_livre' => 0.95, 'cloro_total' => 1.32, 'obs' => null],
            ['data' => '16/06/2026', 'hora' => '12:00', 'ph' => 7.00, 'temp' => 29.0, 'cloro_livre' => 0.28, 'cloro_total' => 0.84, 'obs' => null],
            ['data' => '16/06/2026', 'hora' => '16:00', 'ph' => 7.00, 'temp' => 29.1, 'cloro_livre' => 0.31, 'cloro_total' => 0.40, 'obs' => null],
            ['data' => '16/06/2026', 'hora' => '22:00', 'ph' => 7.00, 'temp' => 29.2, 'cloro_livre' => 0.53, 'cloro_total' => 0.89, 'obs' => null],
            ['data' => '17/06/2026', 'hora' => '08:00', 'ph' => 7.05, 'temp' => 28.9, 'cloro_livre' => 0.27, 'cloro_total' => 0.56, 'obs' => null],
            ['data' => '17/06/2026', 'hora' => '12:00', 'ph' => 7.25, 'temp' => 29.0, 'cloro_livre' => 0.73, 'cloro_total' => 1.00, 'obs' => null],
            ['data' => '17/06/2026', 'hora' => '16:00', 'ph' => 6.90, 'temp' => 28.7, 'cloro_livre' => 1.93, 'cloro_total' => 2.14, 'obs' => null],
            ['data' => '17/06/2026', 'hora' => '22:00', 'ph' => 7.30, 'temp' => 28.8, 'cloro_livre' => 0.48, 'cloro_total' => 0.93, 'obs' => null],
            ['data' => '18/06/2026', 'hora' => '08:00', 'ph' => 7.30, 'temp' => 28.6, 'cloro_livre' => 0.17, 'cloro_total' => 0.40, 'obs' => null],
            ['data' => '18/06/2026', 'hora' => '12:00', 'ph' => 7.20, 'temp' => 28.7, 'cloro_livre' => 0.16, 'cloro_total' => 0.37, 'obs' => null],
            ['data' => '18/06/2026', 'hora' => '16:00', 'ph' => 7.10, 'temp' => 28.4, 'cloro_livre' => 0.28, 'cloro_total' => 0.85, 'obs' => null],
            ['data' => '18/06/2026', 'hora' => '22:00', 'ph' => 7.10, 'temp' => 28.5, 'cloro_livre' => 0.44, 'cloro_total' => 0.89, 'obs' => null],
            ['data' => '19/06/2026', 'hora' => '08:00', 'ph' => 7.10, 'temp' => 28.0, 'cloro_livre' => 1.54, 'cloro_total' => 1.60, 'obs' => null],
            ['data' => '19/06/2026', 'hora' => '12:00', 'ph' => 7.10, 'temp' => 28.1, 'cloro_livre' => 1.31, 'cloro_total' => 1.52, 'obs' => null],
            ['data' => '19/06/2026', 'hora' => '16:00', 'ph' => 7.40, 'temp' => 28.3, 'cloro_livre' => 1.30, 'cloro_total' => 1.42, 'obs' => null],
            ['data' => '19/06/2026', 'hora' => '22:00', 'ph' => 7.20, 'temp' => 28.4, 'cloro_livre' => 1.31, 'cloro_total' => 1.75, 'obs' => null],
            ['data' => '20/06/2026', 'hora' => '08:00', 'ph' => 7.30, 'temp' => 28.2, 'cloro_livre' => 0.13, 'cloro_total' => 0.33, 'obs' => null],
            ['data' => '20/06/2026', 'hora' => '12:00', 'ph' => 7.20, 'temp' => 28.3, 'cloro_livre' => 0.11, 'cloro_total' => 0.47, 'obs' => null],
            ['data' => '20/06/2026', 'hora' => '16:00', 'ph' => 7.30, 'temp' => 28.4, 'cloro_livre' => 0.10, 'cloro_total' => 0.49, 'obs' => null],
            ['data' => '22/06/2026', 'hora' => '08:00', 'ph' => 7.55, 'temp' => 28.9, 'cloro_livre' => 2.28, 'cloro_total' => 2.52, 'obs' => null],
            ['data' => '22/06/2026', 'hora' => '12:00', 'ph' => 7.53, 'temp' => 29.0, 'cloro_livre' => 2.54, 'cloro_total' => 3.35, 'obs' => null],
            ['data' => '22/06/2026', 'hora' => '16:00', 'ph' => 7.30, 'temp' => 29.2, 'cloro_livre' => 1.98, 'cloro_total' => 2.28, 'obs' => null],
            ['data' => '22/06/2026', 'hora' => '22:00', 'ph' => 7.20, 'temp' => 29.5, 'cloro_livre' => 1.10, 'cloro_total' => 1.41, 'obs' => null],
            ['data' => '23/06/2026', 'hora' => '08:00', 'ph' => 7.20, 'temp' => 29.6, 'cloro_livre' => 0.22, 'cloro_total' => 0.36, 'obs' => null],
            ['data' => '23/06/2026', 'hora' => '12:00', 'ph' => 7.20, 'temp' => 29.8, 'cloro_livre' => 1.03, 'cloro_total' => 1.58, 'obs' => null],
            ['data' => '23/06/2026', 'hora' => '16:00', 'ph' => 7.20, 'temp' => 29.9, 'cloro_livre' => 0.38, 'cloro_total' => 0.61, 'obs' => null],
            ['data' => '23/06/2026', 'hora' => '22:00', 'ph' => 7.10, 'temp' => 30.1, 'cloro_livre' => 0.78, 'cloro_total' => 0.98, 'obs' => null],
            ['data' => '24/06/2026', 'hora' => '08:00', 'ph' => 7.30, 'temp' => 29.9, 'cloro_livre' => 1.48, 'cloro_total' => 1.89, 'obs' => null],
            ['data' => '24/06/2026', 'hora' => '12:00', 'ph' => 7.30, 'temp' => 29.8, 'cloro_livre' => 1.15, 'cloro_total' => 1.26, 'obs' => null],
            ['data' => '24/06/2026', 'hora' => '16:00', 'ph' => 7.30, 'temp' => 30.0, 'cloro_livre' => 0.40, 'cloro_total' => 0.88, 'obs' => null],
            ['data' => '24/06/2026', 'hora' => '22:00', 'ph' => 7.20, 'temp' => 29.8, 'cloro_livre' => 1.25, 'cloro_total' => 1.66, 'obs' => null],
            ['data' => '25/06/2026', 'hora' => '08:00', 'ph' => 6.80, 'temp' => 29.6, 'cloro_livre' => 1.35, 'cloro_total' => 1.52, 'obs' => null],
            ['data' => '25/06/2026', 'hora' => '12:00', 'ph' => null, 'temp' => null, 'cloro_livre' => null, 'cloro_total' => null, 'obs' => '* ENCERRADA AGUA NÃO CONFORME'],
        ];

        $total = count($records);
        $inserted = 0;
        $skipped = 0;

        foreach ($records as $item) {
            $registadoEm = Carbon::createFromFormat('d/m/Y H:i', $item['data'].' '.$item['hora']);

            // Verificar se o registo já existe para esta piscina neste horário
            $exists = DailyRecord::where('pool_id', 2)
                ->where('registado_em', $registadoEm)
                ->exists();

            if ($exists) {
                $skipped++;
                $this->line("Registo em {$item['data']} {$item['hora']} já existe. A saltar...");

                continue;
            }

            DailyRecord::create([
                'pool_id' => 2,
                'user_id' => $user->id,
                'registado_em' => $registadoEm,
                'ph' => $item['ph'],
                'temperatura' => $item['temp'],
                'cloro_livre' => $item['cloro_livre'],
                'cloro_total' => $item['cloro_total'],
                'observacoes' => $item['obs'],
                'transparencia' => 2.0, // Conforme por omissão para piscinas gerais
                'e_correcao' => false,
            ]);

            $inserted++;
            $this->line("Registo em {$item['data']} {$item['hora']} importado.");
        }

        $this->info("Importação concluída! Total processados: {$total} | Importados: {$inserted} | Saltados: {$skipped}");

        return 0;
    }
}
