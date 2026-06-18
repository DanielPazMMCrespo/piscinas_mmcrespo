<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\StockInsufficientException;
use PHPUnit\Framework\TestCase;

class StockInsufficientExceptionTest extends TestCase
{
    public function test_exception_is_instantiated_with_context(): void
    {
        $exception = new StockInsufficientException(
            productName: 'Cloro em Pó',
            requested: 10.5,
            available: 3.2,
            installationId: 1,
        );

        $this->assertSame('Cloro em Pó', $exception->productName);
        $this->assertSame(10.5, $exception->requested);
        $this->assertSame(3.2, $exception->available);
        $this->assertSame(1, $exception->installationId);
    }

    public function test_exception_message_is_informative(): void
    {
        $exception = new StockInsufficientException(
            productName: 'Cloro em Pó',
            requested: 10.5,
            available: 3.2,
            installationId: 1,
        );

        $this->assertStringContainsString('Cloro em Pó', $exception->getMessage());
        $this->assertStringContainsString('10.5', $exception->getMessage());
        $this->assertStringContainsString('3.2', $exception->getMessage());
    }

    public function test_friendly_message_is_user_readable(): void
    {
        $exception = new StockInsufficientException(
            productName: 'Cloro em Pó',
            requested: 10.5,
            available: 3.2,
            installationId: 1,
        );

        $friendly = $exception->friendlyMessage();

        $this->assertStringContainsString('Cloro em Pó', $friendly);
        $this->assertStringContainsString('3.2', $friendly);
        // Insuficiência: 10.5 - 3.2 = 7.3
        $this->assertStringContainsString('7.3', $friendly);
        $this->assertStringNotContainsString('Exception', $friendly);
    }

    public function test_friendly_message_handles_zero_available(): void
    {
        $exception = new StockInsufficientException(
            productName: 'Soda Cáustica',
            requested: 5.0,
            available: 0.0,
            installationId: 2,
        );

        $friendly = $exception->friendlyMessage();

        $this->assertStringContainsString('Soda Cáustica', $friendly);
        $this->assertStringContainsString('0', $friendly);
        $this->assertStringContainsString('5', $friendly);
    }

    public function test_exception_is_throwable(): void
    {
        $this->expectException(StockInsufficientException::class);

        throw new StockInsufficientException(
            productName: 'Teste',
            requested: 10.0,
            available: 5.0,
            installationId: 1,
        );
    }
}
