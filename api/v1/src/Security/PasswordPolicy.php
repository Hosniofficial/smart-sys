<?php

declare(strict_types=1);

namespace App\Security;

final class PasswordPolicy
{
    private static ?array $policy = null;

    public static function validationMessage(string $password): ?string
    {
        $policy = self::policy();
        $length = strlen($password);

        if ($length < (int) ($policy['min_length'] ?? 8)) {
            return 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
        }

        if ($length > (int) ($policy['max_length'] ?? 72)) {
            return 'كلمة المرور طويلة جدًا؛ الحد الأقصى 72 بايتًا';
        }

        return null;
    }

    public static function assertValid(string $password): void
    {
        $message = self::validationMessage($password);
        if ($message !== null) {
            throw new \InvalidArgumentException($message);
        }
    }

    private static function policy(): array
    {
        if (self::$policy === null) {
            $securityConfig = require dirname(__DIR__, 4) . '/config/security.php';
            self::$policy = $securityConfig['password_policy'] ?? [];
        }

        return self::$policy;
    }
}
