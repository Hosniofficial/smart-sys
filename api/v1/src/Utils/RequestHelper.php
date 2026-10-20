<?php

declare(strict_types=1);

namespace App\Utils;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * RequestHelper
 *
 * Centralises HTTP request utility functions used across handlers and middleware.
 *
 * Replaces the 5 duplicated getClientIp() implementations found in:
 *   - StrictSubscriptionHandler
 *   - StrictSubscriptionMiddleware
 *   - RequestRateLimiter
 *   - RequestLoggingMiddleware
 *   - SecurityLogger
 *
 * Resolution order (most-trusted → least-trusted):
 *   1. CF-Connecting-IP  (Cloudflare — set by the CDN, not the client)
 *   2. X-Real-IP         (single-IP proxy header)
 *   3. X-Forwarded-For   (rightmost non-private IP when $trustProxy = true,
 *                         leftmost IP when $trustProxy = false — default)
 *   4. REMOTE_ADDR       (direct connection)
 */
final class RequestHelper
{
    /**
     * Extract the real client IP from a PSR-7 request.
     *
     * @param Request $request
     * @param bool    $trustProxy  Set true only when running behind a trusted
     *                             reverse-proxy (nginx, AWS ALB, Cloudflare).
     *                             When false, X-Forwarded-For is ignored to
     *                             prevent IP spoofing.
     * @return string  Always returns a non-empty string; falls back to '127.0.0.1'.
     */
    public static function getClientIp(Request $request, bool $trustProxy = true): string
    {
        $server = $request->getServerParams();

        // 1. Cloudflare — CF-Connecting-IP is set by the CDN and cannot be
        //    spoofed by the end-user when Cloudflare is in the chain.
        $cf = trim($request->getHeaderLine('CF-Connecting-IP'));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        if ($trustProxy) {
            // 2. X-Real-IP — single IP set by nginx/HAProxy
            $realIp = trim($request->getHeaderLine('X-Real-IP'));
            if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
                return $realIp;
            }

            // 3. X-Forwarded-For — take the RIGHTMOST public IP
            //    (rightmost = added by the last trusted proxy, not spoofable)
            $xff = $request->getHeaderLine('X-Forwarded-For');
            if ($xff !== '') {
                $parts = array_reverse(array_map('trim', explode(',', $xff)));
                foreach ($parts as $ip) {
                    if (
                        $ip !== '' &&
                        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    ) {
                        return $ip;
                    }
                }
            }
        }

        // 4. Direct connection
        $remote = $server['REMOTE_ADDR'] ?? '';
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return '127.0.0.1';
    }

    /**
     * Extract the real client IP from $_SERVER (for contexts without a
     * PSR-7 Request object).
     *
     * @return string
     */
    public static function getClientIpFromServer(): string
    {
        $keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($keys as $key) {
            $value = $_SERVER[$key] ?? '';
            if ($value === '') {
                continue;
            }

            // X-Forwarded-For may contain a comma-separated list
            $ip = trim(explode(',', $value)[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '127.0.0.1';
    }
}
