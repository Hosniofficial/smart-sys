<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Environment helpers for platform deployments.
 *
 * Production php.ini files commonly set variables_order to "GPCS" (no "E"), which leaves $_ENV empty
 * even though the platform (Railway, Render, Docker) injected real process environment variables.
 * The application reads configuration from $_ENV, so those variables must be copied in explicitly;
 * otherwise startup validation reports every secret as missing.
 */
final class Environment
{
    /**
     * Copy process environment variables into $_ENV without overriding values that are already set.
     *
     * @param array<string,string>|null $source defaults to getenv()
     */
    public static function hydrateFromProcess(?array $source = null): void
    {
        $variables = $source ?? getenv();
        if (!is_array($variables)) {
            return;
        }

        foreach ($variables as $name => $value) {
            if (is_string($name) && is_string($value) && !array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }
        }
    }
}
