<?php declare(strict_types=1);
namespace App\Services;


use Illuminate\Support\Facades\Log;

class StructuredLogger
{
    /**
     * Log daily record creation with context.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $context
     */
    public static function logDailyRecordCreated(array $record, int $durationMs, array $context = []): void
    {
        Log::channel(config('logging.default', 'stack'))->info('Daily record created', [
            'record_id' => $record['id'] ?? null,
            'pool_id' => $record['pool_id'] ?? null,
            'chlorine_total' => $record['cloro_total'] ?? null,
            'chlorine_combined' => $record['cloro_combinado'] ?? null,
            'ph' => $record['ph'] ?? null,
            'temperature' => $record['temperatura_agua'] ?? null,
            'turbidity' => $record['turbidez'] ?? null,
            'operation_type' => 'daily_record_create',
            'duration_ms' => $durationMs,
            'is_correction' => $record['e_correcao'] ?? false,
            ...$context,
        ]);

        // Also log to JSON channel if enabled
        if (config('logging.channels.json')) {
            Log::channel('json')->info('Daily record created', [
                'record_id' => $record['id'] ?? null,
                'pool_id' => $record['pool_id'] ?? null,
                'operation_type' => 'daily_record_create',
                'duration_ms' => $durationMs,
                'conformity_status' => $record['conformidade'] ?? 'unknown',
                ...$context,
            ]);
        }
    }

    /**
     * Log stock transfer operation.
     *
     * @param array<string, mixed> $context
     */
    public static function logStockTransferCompleted(
        int $sourceId,
        int $destinationId,
        string $sourceType,
        string $destinationType,
        string $productName,
        float $quantity,
        string $unit,
        int $durationMs,
        array $context = []
    ): void {
        Log::channel(config('logging.default', 'stack'))->info('Stock transfer completed', [
            'source_id' => $sourceId,
            'source_type' => $sourceType,
            'destination_id' => $destinationId,
            'destination_type' => $destinationType,
            'product_name' => $productName,
            'quantity' => $quantity,
            'unit' => $unit,
            'operation_type' => 'stock_transfer',
            'duration_ms' => $durationMs,
            ...$context,
        ]);

        if (config('logging.channels.json')) {
            Log::channel('json')->info('Stock transfer completed', [
                'source_id' => $sourceId,
                'source_type' => $sourceType,
                'destination_id' => $destinationId,
                'destination_type' => $destinationType,
                'product_name' => $productName,
                'quantity' => $quantity,
                'operation_type' => 'stock_transfer',
                'duration_ms' => $durationMs,
                ...$context,
            ]);
        }
    }

    /**
     * Log incident resolution.
     *
     * @param array<string, mixed> $context
     */
    public static function logIncidentResolved(
        int $incidentId,
        int $poolId,
        string $incidentType,
        string $resolution,
        int $durationMinutes,
        array $context = []
    ): void {
        Log::channel(config('logging.default', 'stack'))->info('Incident resolved', [
            'incident_id' => $incidentId,
            'pool_id' => $poolId,
            'incident_type' => $incidentType,
            'resolution' => $resolution,
            'time_to_resolution_minutes' => $durationMinutes,
            'operation_type' => 'incident_resolve',
            ...$context,
        ]);

        if (config('logging.channels.json')) {
            Log::channel('json')->info('Incident resolved', [
                'incident_id' => $incidentId,
                'pool_id' => $poolId,
                'incident_type' => $incidentType,
                'operation_type' => 'incident_resolve',
                'time_to_resolution_minutes' => $durationMinutes,
                ...$context,
            ]);
        }
    }

    /**
     * Log sensor communication failure.
     *
     * @param array<string, mixed> $context
     */
    public static function logSensorCommunicationFailure(
        string $deviceId,
        string $error,
        int $retryCount,
        array $context = []
    ): void {
        Log::channel(config('logging.default', 'stack'))->warning('Sensor communication failure', [
            'device_id' => $deviceId,
            'error' => $error,
            'retry_count' => $retryCount,
            'operation_type' => 'sensor_sync_failure',
            ...$context,
        ]);

        if (config('logging.channels.json')) {
            Log::channel('json')->warning('Sensor communication failure', [
                'device_id' => $deviceId,
                'error' => $error,
                'retry_count' => $retryCount,
                'operation_type' => 'sensor_sync_failure',
                ...$context,
            ]);
        }
    }

    /**
     * Log stock insufficiency warning.
     *
     * @param array<string, mixed> $context
     */
    public static function logStockInsufficient(
        string $productName,
        float $requested,
        float $available,
        string $unit,
        int $installationId,
        array $context = []
    ): void {
        Log::channel(config('logging.default', 'stack'))->warning('Stock insufficient', [
            'product_name' => $productName,
            'requested' => $requested,
            'available' => $available,
            'unit' => $unit,
            'installation_id' => $installationId,
            'operation_type' => 'stock_insufficient',
            ...$context,
        ]);

        if (config('logging.channels.json')) {
            Log::channel('json')->warning('Stock insufficient', [
                'product_name' => $productName,
                'requested' => $requested,
                'available' => $available,
                'unit' => $unit,
                'installation_id' => $installationId,
                'operation_type' => 'stock_insufficient',
                ...$context,
            ]);
        }
    }

    /**
     * Log validation error.
     *
     * @param array<string, mixed> $errors
     * @param array<string, mixed> $context
     */
    public static function logValidationError(string $resource, array $errors, array $context = []): void
    {
        Log::channel(config('logging.default', 'stack'))->warning('Validation error', [
            'resource' => $resource,
            'errors' => $errors,
            'operation_type' => 'validation_error',
            ...$context,
        ]);

        if (config('logging.channels.json')) {
            Log::channel('json')->warning('Validation error', [
                'resource' => $resource,
                'error_count' => count($errors),
                'operation_type' => 'validation_error',
                ...$context,
            ]);
        }
    }
}
