<?php

namespace App\Exceptions;

/** Stock shortfall carrying product and quantity details for API error responses. */
class InsufficientStockException extends \Exception
{
    public readonly int $productId;
    public readonly float $availableQty;
    public readonly float $requestedQty;

    public function __construct(
        string $message,
        int $productId,
        float $availableQty,
        float $requestedQty = 0
    ) {
        parent::__construct($message);
        $this->productId = $productId;
        $this->availableQty = $availableQty;
        $this->requestedQty = $requestedQty;
    }

    public function toArray(): array
    {
        return [
            'status' => 'error',
            'error_code' => 'insufficient_stock',
            'product_id' => $this->productId,
            'available_qty' => $this->availableQty,
            'requested_qty' => $this->requestedQty,
            'message' => $this->getMessage(),
        ];
    }
}
