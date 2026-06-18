<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\SensorCommunicationException;
use PHPUnit\Framework\TestCase;

class SensorCommunicationExceptionTest extends TestCase
{
    public function test_exception_is_instantiated_with_minimal_context(): void
    {
        $exception = new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
        );

        $this->assertSame('BL132-ABC123', $exception->deviceId);
        $this->assertSame(1, $exception->attempt);
        $this->assertNull($exception->statusCode);
        $this->assertNull($exception->originalError);
    }

    public function test_exception_with_status_code(): void
    {
        $exception = new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
            attempt: 2,
            statusCode: 503,
        );

        $this->assertSame(503, $exception->statusCode);
        $this->assertStringContainsString('503', $exception->getMessage());
    }

    public function test_exception_with_original_error(): void
    {
        $originalError = new \Exception('Connection timeout');
        $exception = new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
            attempt: 1,
            originalError: $originalError,
        );

        $this->assertSame($originalError, $exception->originalError);
        $this->assertStringContainsString('Connection timeout', $exception->getMessage());
    }

    public function test_should_retry_returns_true_for_network_errors(): void
    {
        // statusCode null = falha de rede, sem HTTP response
        $exception = new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
            statusCode: null,
        );

        $this->assertTrue($exception->shouldRetry());
    }

    public function test_should_retry_returns_true_for_5xx_errors(): void
    {
        $exception500 = new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
            statusCode: 500,
        );

        $this->assertTrue($exception500->shouldRetry());

        $exception503 = new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
            statusCode: 503,
        );

        $this->assertTrue($exception503->shouldRetry());
    }

    public function test_should_retry_returns_false_for_4xx_errors(): void
    {
        $exception401 = new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
            statusCode: 401,
        );

        $this->assertFalse($exception401->shouldRetry());

        $exception404 = new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
            statusCode: 404,
        );

        $this->assertFalse($exception404->shouldRetry());
    }

    public function test_friendly_message_is_user_readable(): void
    {
        $exception = new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
            attempt: 3,
            statusCode: 503,
        );

        $friendly = $exception->friendlyMessage();

        $this->assertStringNotContainsString('Exception', $friendly);
        $this->assertStringNotContainsString('BL132-ABC123', $friendly); // Device ID ocultado
        $this->assertStringContainsString('indisponível', $friendly);
        $this->assertStringContainsString('funcional', $friendly);
    }

    public function test_exception_is_throwable(): void
    {
        $this->expectException(SensorCommunicationException::class);

        throw new SensorCommunicationException(
            deviceId: 'BL132-ABC123',
        );
    }
}
