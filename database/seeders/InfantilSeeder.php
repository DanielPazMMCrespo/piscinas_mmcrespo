<?php declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InfantilSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/insert_infantil.sql');
        
        if (!file_exists($path)) {
            $this->command->error("SQL file not found at: {$path}");
            return;
        }

        $this->command->info('Importing Infantil daily records from SQL...');
        
        DB::unprepared(file_get_contents($path));

        $this->command->info('Infantil daily records imported successfully!');
    }
}
