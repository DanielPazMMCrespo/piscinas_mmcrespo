<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Lançada quando a quantidade disponível em stock é insuficiente para a operação.
 *
 * Não deve bloquear a operação de registo diário (desconta até zero + avisa),
 * mas deve ser capturada para logging e notificação ao utilizador.
 *
 * Contexto debug: `product_name`, `requested`, `available`, `installation_id`.
 */
class StockInsufficientException extends \Exception
{
    public function __construct(
        public readonly string $productName,
        public readonly float $requested,
        public readonly float $available,
        public readonly int $installationId,
    ) {
        $message = "Stock insuficiente: {$this->productName}. "
            ."Pedido: {$this->requested}, Disponível: {$this->available}.";
        parent::__construct($message);
    }

    /** Mensagem amigável para o UI. */
    public function friendlyMessage(): string
    {
        $shortage = $this->requested - $this->available;
        return "Quantidade insuficiente de {$this->productName}. "
            ."Disponível: {$this->available}. Insuficiência: {$shortage} unidades.";
    }
}
