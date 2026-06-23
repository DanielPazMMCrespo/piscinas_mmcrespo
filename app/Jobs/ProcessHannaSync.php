<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessHannaSync implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('sensor-sync');
    }

    public function handle(): void
    {
        Log::info('hanna_sync_job_started');

        Artisan::call('hanna:sync', [], null);

        Log::info('hanna_sync_job_completed');
    }

    public function failed(Throwable $exception): void
    {
        Log::error('hanna_sync_job_failed', [
            'exception' => $exception->getMessage(),
        ]);
    }
}
