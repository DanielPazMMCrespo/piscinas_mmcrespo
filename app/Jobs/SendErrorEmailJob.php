<?php declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendErrorEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $to,
        protected string $subject,
        protected string $body
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Mail::raw($this->body, function ($msg): void {
                $msg->to($this->to)->subject($this->subject);
            });
        } catch (\Throwable $e) {
            logger()->error('Falha ao enviar email de erro crítico: ' . $e->getMessage());
        }
    }
}
