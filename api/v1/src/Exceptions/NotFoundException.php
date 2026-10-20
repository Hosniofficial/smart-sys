<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Resource-not-found exception carrying HTTP status code 404. */
class NotFoundException extends \RuntimeException
{
    public function __construct(
        string $message = 'السجل المطلوب غير موجود',
        \Throwable $previous = null
    ) {
        parent::__construct($message, 404, $previous);
    }
}
