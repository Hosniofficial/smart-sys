<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Signals that a transaction was rejected by liquidity policy, with response context. */
class InsufficientLiquidityException extends RuntimeException
{
    private array $context;

    public function __construct(
        string $message = 'Insufficient liquidity to complete transaction',
        int $code = 402,
        array $context = []
    ) {
        parent::__construct($message, $code);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getHttpStatusCode(): int
    {
        return $this->code ?: 402; // 402 Payment Required
    }
}
