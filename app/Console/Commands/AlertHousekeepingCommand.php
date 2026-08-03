<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AlertState;
use Illuminate\Console\Command;

class AlertHousekeepingCommand extends Command
{
    protected $signature = 'alerts:housekeeping';

    protected $description = 'Prune old alert states (>7 days) and auto-resolve expired alerts';

    public function handle(): int
    {
        // Poda de estados com mais de 7 dias
        $pruned = AlertState::query()
            ->where('moved_at', '<', now()->subDays(7))
            ->delete();

        if ($pruned > 0) {
            $this->info("Pruned $pruned old alert states");
        }

        return self::SUCCESS;
    }
}
