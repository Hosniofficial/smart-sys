<?php

/**
 * Security Configuration
 * This file contains all security-related settings for the ERP system
 */

return [
    // JWT Configuration
    'jwt' => [
        // Read from environment only (no hardcoded secrets) per security best practices
        'secret' => $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? null,
        'refresh_secret' => $_ENV['JWT_REFRESH_SECRET'] ?? getenv('JWT_REFRESH_SECRET') ?? null,
        'access_token_expiry' => (int)($_ENV['JWT_ACCESS_TOKEN_EXPIRY'] ?? 3600), // 1 hour
        'refresh_token_expiry' => (int)($_ENV['JWT_REFRESH_TOKEN_EXPIRY'] ?? 2592000), // 30 days
        'algorithm' => $_ENV['JWT_ALGORITHM'] ?? 'HS256',
        'leeway' => 60, // 1 minute leeway for clock skew
    ],

    // Debug Configuration (safe, does NOT print secrets)
    // Note: full debug config block is defined later in this file under 'debug' key.
    // ⚠️ Removed duplicate 'debug' key — PHP silently overwrites the first with the second.

    // Security Logging Configuration
    'security_logging' => [
        'enabled' => ($_ENV['SECURITY_LOGGING_ENABLED'] ?? 'true') === 'true',
        'log_auth_attempts' => true,
        'log_failed_logins' => true,
        'log_sensitive_operations' => true,
        'log_user_management' => true,
        'log_role_changes' => true,
        'log_permission_changes' => true,
        'retention_days' => (int)($_ENV['SECURITY_LOG_RETENTION_DAYS'] ?? 365),
    ],
    
    // Rate Limiting Configuration
    'rate_limiting' => [
        'enabled' => ($_ENV['RATE_LIMIT_ENABLED'] ?? 'true') === 'true',
        'max_attempts' => (int)($_ENV['RATE_LIMIT_MAX_ATTEMPTS'] ?? 60),
        'window' => (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60),
        'cache_prefix' => 'rate_limit_',
        'trust_proxy' => filter_var($_ENV['TRUST_PROXY'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        'trusted_proxies' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($_ENV['TRUSTED_PROXY_IPS'] ?? ''))
        ))),
        'ip_whitelist' => [
            '127.0.0.1',
            '::1',
        ],
        // Per-path rules override the global max/window for specific endpoints
        'rules' => [
            // Login: X attempts per window (from ENV)
            [
                'path' => '/auth/login',
                'max_attempts' => (int)($_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 10),
                'window' => (int)($_ENV['RATE_LIMIT_LOGIN_WINDOW'] ?? 600)
            ],
            // Register: 5 attempts per 30 minutes per IP
            [
                'path' => '/auth/register',
                'max_attempts' => 5,
                'window' => 1800
            ],
            // Refresh: 30 requests per 5 minutes per IP
            [
                'path' => '/auth/refresh',
                'max_attempts' => 30,
                'window' => 300
            ],
            // Forgot password: 5 requests per 30 minutes per IP
            [
                'path' => '/auth/forgot-password',
                'max_attempts' => 5,
                'window' => 1800
            ],
            // Password reset: 5 requests per 30 minutes per IP
            [
                'path' => '/auth/reset-password',
                'max_attempts' => 5,
                'window' => 1800
            ],
        ],
        'headers' => [
            'enabled' => true,
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset',
        ],
    ],
    
    // Security Headers Configuration
    'security_headers' => [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
        'Cross-Origin-Resource-Policy' => 'cross-origin',
    ],
    
    // Content Security Policy
    // Note: 'unsafe-eval' removed (Sprint 0). Vite production builds don't need it.
    // 'unsafe-inline' on script-src is still present because Vue PrimeVue's runtime
    // inlines a few script tags; if you migrate to strict CSP, also generate nonces
    // for runtime-injected scripts.
    'csp' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-inline'"],
        'style-src' => ["'self'", "'unsafe-inline'", 'fonts.googleapis.com', 'cdn.jsdelivr.net'],
        'img-src' => ["'self'", 'data:', 'blob:', 'cdn.jsdelivr.net'],
        'font-src' => ["'self'", 'data:', 'fonts.gstatic.com', 'cdn.jsdelivr.net'],
        'connect-src' => ["'self'"],
        'media-src' => ["'self'"],
        'object-src' => ["'none'"],
        'child-src' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-src' => ["'self'"],
        'frame-ancestors' => ["'none'"],
        'base-uri' => ["'self'"],
        'upgrade-insecure-requests' => [],
    ],
    
    // Password Policy
    'password_policy' => [
        'min_length' => 8,
        'max_length' => 128,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_symbols' => false,
        'prevent_common_passwords' => true,
        'prevent_username_in_password' => true,
        'expiry_days' => 90, // Password expires after 90 days
        'history_count' => 5, // Remember last 5 passwords
    ],
    
    // Account Lockout Policy
    'account_lockout' => [
        'enabled' => true,
        'max_failed_attempts' => 5,
        'lockout_duration' => 900, // 15 minutes
        'reset_time' => 3600, // Reset failed attempts after 1 hour
        'notify_on_lockout' => true,
    ],
    
    // Session Configuration
    'session' => [
        'timeout' => 3600, // 1 hour
        'regenerate_id' => true,
        'secure' => true, // Only over HTTPS in production
        'httponly' => true,
        'samesite' => 'Strict',
    ],
    
    // Encryption Configuration
    'encryption' => [
        'key' => $_ENV['ENCRYPTION_KEY'] ?? null,
        'cipher' => 'AES-256-GCM',
    ],
    
    // Security Event Types
    'event_types' => [
        // Authentication Events
        'user.login' => ['severity' => 'info', 'retention_days' => 90],
        'login.failed' => ['severity' => 'warning', 'retention_days' => 365],
        'user.logout' => ['severity' => 'info', 'retention_days' => 30],
        'token.blacklisted' => ['severity' => 'notice', 'retention_days' => 90],
        
        // User Management Events
        'user.created' => ['severity' => 'notice', 'retention_days' => 365],
        'user.updated' => ['severity' => 'notice', 'retention_days' => 365],
        'user.deleted' => ['severity' => 'warning', 'retention_days' => 365],
        'user.password_change' => ['severity' => 'notice', 'retention_days' => 365],
        'user.role_change' => ['severity' => 'warning', 'retention_days' => 365],
        
        // Security Events
        'security.policy_violation' => ['severity' => 'error', 'retention_days' => 365],
        'security.rate_limit_exceeded' => ['severity' => 'warning', 'retention_days' => 90],
        'security.suspicious_activity' => ['severity' => 'critical', 'retention_days' => 365],
        'security.brute_force' => ['severity' => 'critical', 'retention_days' => 365],
        'security.unauthorized_access' => ['severity' => 'critical', 'retention_days' => 365],
        
        // System Events
        'system.initialized' => ['severity' => 'info', 'retention_days' => 365],
        'system.maintenance' => ['severity' => 'notice', 'retention_days' => 90],
        'system.backup' => ['severity' => 'info', 'retention_days' => 90],
        
        // Data Events
        'data.export' => ['severity' => 'notice', 'retention_days' => 365],
        'data.import' => ['severity' => 'notice', 'retention_days' => 365],
        'data.deletion' => ['severity' => 'warning', 'retention_days' => 365],
    ],
    
    // Security Monitoring
    'monitoring' => [
        'enabled' => true,
        'alert_on_critical' => true,
        'alert_email' => $_ENV['SECURITY_ALERT_EMAIL'] ?? null,
        'max_events_per_minute' => 100,
        'suspicious_patterns' => [
            'multiple_failed_logins' => 5,
            'rapid_requests' => 50,
            'unusual_ip_activity' => true,
        ],
    ],
    
    // Backup and Recovery
    'backup' => [
        'security_logs' => true,
        'encryption_enabled' => true,
        'retention_days' => 365,
        'compress' => true,
    ],
    
    // CORS Configuration
    'cors' => [
        // ✅ Origins loaded from environment variable CORS_ALLOWED_ORIGINS (comma-separated).
        // Falls back to localhost dev origins when the variable is absent.
        // Never use '*' with credentials:true — always list origins explicitly.
        'origin' => array_values(array_filter(
            array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '')),
            fn($o) => $o !== ''
        )) ?: [
            'http://localhost:5173',
            'http://localhost:3000',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:3000',
        ],
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'headers' => [
            'Content-Type',
            'Authorization',
            'X-Requested-With',
            'X-Tenant-ID',
            'X-Tenant-Id',
            'X-Device-Fingerprint',
            'Accept',
            'Accept-Language',
            'Origin',
            'Idempotency-Key',
        ],
        'max_age'     => 86400,
        // credentials: true is REQUIRED for HttpOnly cookies to be sent cross-origin.
        // Ensure the origin list above is explicit (never '*') when this is true.
        'credentials' => true,
    ],
    
    // Development/Debug Settings
    'debug' => [
        'enabled'                => ($_ENV['APP_DEBUG'] ?? 'false') === 'true'
                                    || ($_ENV['APP_DEBUG'] ?? false) === true,
        'log_all_requests'       => false,
        'detailed_errors'        => ($_ENV['APP_DEBUG'] ?? 'false') === 'true'
                                    || ($_ENV['APP_DEBUG'] ?? false) === true,
        'security_headers_in_dev' => true,
    ],
];
