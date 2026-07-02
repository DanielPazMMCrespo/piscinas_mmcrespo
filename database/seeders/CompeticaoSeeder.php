<?php declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompeticaoSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/insert_competicao.sql');
        
        if (!file_exists($path)) {
            $this->command->error("SQL file not found at: {$path}");
            return;
        }

        $this->command->info('Importing Competição daily records from SQL...');
        
        DB::unprepared(file_get_contents($path));

        $this->command->info('Competição daily records imported successfully!');
    }
}
