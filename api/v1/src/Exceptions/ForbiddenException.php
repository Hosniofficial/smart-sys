<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Permission-denial exception carrying the required permission and HTTP 403 code. */
class ForbiddenException extends \RuntimeException
{
    private string $requiredPermission;

    public function __construct(
        string $requiredPermission,
        string $message = '',
        \Throwable $previous = null
    ) {
        $this->requiredPermission = $requiredPermission;

        if ($message === '') {
            $message = "ليس لديك صلاحية تنفيذ هذا الإجراء. (مطلوب: {$requiredPermission})";
        }

        parent::__construct($message, 403, $previous);
    }

    public function getRequiredPermission(): string
    {
        return $this->requiredPermission;
    }
}
