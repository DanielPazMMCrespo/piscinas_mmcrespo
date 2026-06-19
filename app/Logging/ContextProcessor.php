<?php declare(strict_types=1);
namespace App\Logging;


use Illuminate\Support\Facades\Auth;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class ContextProcessor implements ProcessorInterface
{
    /**
     * Process the log record to add contextual information.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        // Add request ID if available
        if (request()->has('request_id')) {
            $record['extra']['request_id'] = request()->get('request_id');
        }

        // Add authenticated user ID
        if (Auth::check()) {
            $record['extra']['user_id'] = Auth::user()?->id;
            $record['extra']['user_email'] = Auth::user()?->email;
        }

        // Add pool ID from route if available
        if (request()->route('pool')) {
            $record['extra']['pool_id'] = request()->route('pool');
        }

        // Add timestamp in ISO8601
        $record['extra']['timestamp'] = now()->toIso8601String();

        // Add environment
        $record['extra']['environment'] = config('app.env');

        // Add version (git commit hash)
        $record['extra']['version'] = $this->getAppVersion();

        return $record;
    }

    /**
     * Get application version from git commit hash.
     */
    private function getAppVersion(): string
    {
        try {
            $hash = trim(shell_exec('git rev-parse --short HEAD') ?? '');

            return $hash ?: 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
