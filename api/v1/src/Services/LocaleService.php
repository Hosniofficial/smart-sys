<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Resolves request locale values to the supported Arabic or English labels.
 */
class LocaleService
{
    /**
     * Resolve query override first, then Accept-Language, defaulting to Arabic.
     */
    public static function fromRequest(Request $request): string
    {
        $qp = $request->getQueryParams();
        $explicit = $qp['lang'] ?? ($qp['locale'] ?? null);
        if ($explicit !== null) {
            return self::normalise((string) $explicit);
        }

        $header = $request->getHeaderLine('Accept-Language');
        if ($header !== '') {
            return self::normalise($header);
        }

        return 'ar';
    }

    /**
     * Resolve a locale code or Accept-Language value to "ar" or "en".
     */
    public static function fromString(string $value): string
    {
        return self::normalise($value);
    }

    /** Map the first language tag to Arabic when it starts with "ar"; otherwise English. */
    private static function normalise(string $value): string
    {
        $primary = strtolower(trim(explode(',', explode(';', $value)[0])[0]));

        if (str_starts_with($primary, 'ar')) {
            return 'ar';
        }

        return 'en';
    }
}
