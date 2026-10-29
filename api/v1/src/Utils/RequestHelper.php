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
 * Forwarding headers are used only when the direct peer matches an explicitly
 * trusted proxy. X-Forwarded-For is walked from the nearest hop outwards.
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
    public static function getClientIp(
        Request $request,
        ?bool $trustProxy = null,
        ?array $trustedProxies = null
    ): string
    {
        $server = $request->getServerParams();
        return self::resolveClientIp(
            [
                'CF_CONNECTING_IP' => $request->getHeaderLine('CF-Connecting-IP'),
                'X_REAL_IP' => $request->getHeaderLine('X-Real-IP'),
                'X_FORWARDED_FOR' => $request->getHeaderLine('X-Forwarded-For'),
            ],
            (string) ($server['REMOTE_ADDR'] ?? ''),
            $trustProxy,
            $trustedProxies
        );
    }

    /**
     * Extract the real client IP from $_SERVER (for contexts without a
     * PSR-7 Request object).
     *
     * @return string
     */
    public static function getClientIpFromServer(
        ?bool $trustProxy = null,
        ?array $trustedProxies = null
    ): string
    {
        return self::resolveClientIp(
            [
                'CF_CONNECTING_IP' => (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
                'X_REAL_IP' => (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
                'X_FORWARDED_FOR' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            ],
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $trustProxy,
            $trustedProxies
        );
    }

    private static function resolveClientIp(
        array $headers,
        string $remoteAddress,
        ?bool $trustProxy,
        ?array $trustedProxies
    ): string {
        $remoteAddress = trim($remoteAddress);
        $isValidRemote = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false;
        if (!$isValidRemote) {
            $remoteAddress = '127.0.0.1';
        }

        [$proxyTrustEnabled, $proxyAddresses] = self::proxySettings($trustProxy, $trustedProxies);
        if (!$proxyTrustEnabled || !$isValidRemote || !self::matchesTrustedProxy($remoteAddress, $proxyAddresses)) {
            return $remoteAddress;
        }

        foreach (['CF_CONNECTING_IP', 'X_REAL_IP'] as $header) {
            $candidate = trim((string) ($headers[$header] ?? ''));
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        $forwardedFor = trim((string) ($headers['X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            foreach (array_reverse(array_map('trim', explode(',', $forwardedFor))) as $candidate) {
                if (
                    filter_var($candidate, FILTER_VALIDATE_IP) !== false
                    && !self::matchesTrustedProxy($candidate, $proxyAddresses)
                ) {
                    return $candidate;
                }
            }
        }

        return $remoteAddress;
    }

    private static function proxySettings(?bool $trustProxy, ?array $trustedProxies): array
    {
        if ($trustProxy === null) {
            $configuredTrust = $_ENV['TRUST_PROXY'] ?? getenv('TRUST_PROXY');
            $trustProxy = filter_var(
                $configuredTrust === false || $configuredTrust === null ? 'false' : $configuredTrust,
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if ($trustedProxies === null) {
            $configuredProxies = $_ENV['TRUSTED_PROXY_IPS'] ?? getenv('TRUSTED_PROXY_IPS');
            $trustedProxies = is_string($configuredProxies)
                ? array_filter(array_map('trim', explode(',', $configuredProxies)))
                : [];
        }

        return [$trustProxy, array_values($trustedProxies)];
    }

    private static function matchesTrustedProxy(string $address, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $proxy) {
            $proxy = trim((string) $proxy);
            if ($proxy === '') {
                continue;
            }

            if (strpos($proxy, '/') === false) {
                if (filter_var($proxy, FILTER_VALIDATE_IP) !== false && $address === $proxy) {
                    return true;
                }
                continue;
            }

            [$network, $prefix] = explode('/', $proxy, 2);
            $networkBytes = @inet_pton($network);
            $addressBytes = @inet_pton($address);
            if (
                $networkBytes === false
                || $addressBytes === false
                || strlen($networkBytes) !== strlen($addressBytes)
                || !ctype_digit($prefix)
            ) {
                continue;
            }

            $prefixLength = (int) $prefix;
            $maxPrefixLength = strlen($networkBytes) * 8;
            if ($prefixLength > $maxPrefixLength) {
                continue;
            }

            $wholeBytes = intdiv($prefixLength, 8);
            $remainingBits = $prefixLength % 8;
            if (
                substr($networkBytes, 0, $wholeBytes) !== substr($addressBytes, 0, $wholeBytes)
            ) {
                continue;
            }

            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xff << (8 - $remainingBits)) & 0xff;
            if (
                (ord($networkBytes[$wholeBytes]) & $mask)
                === (ord($addressBytes[$wholeBytes]) & $mask)
            ) {
                return true;
            }
        }

        return false;
    }
}
