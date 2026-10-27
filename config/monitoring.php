<?php

/**
 * Monitoring and Error Tracking Configuration
 * Handles Sentry, Logging, and Error Tracking
 */

$environment = $_ENV['APP_ENV'] ?? 'production';
$sentryConfigured = filter_var($_ENV['SENTRY_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN);
$sentryEnabled = false;

// Initialize Sentry for error tracking (only if package is installed)
$sentryDsn = $_ENV['SENTRY_DSN'] ?? null;

if ($sentryConfigured && $sentryDsn && @class_exists('\\Sentry\\ClientBuilder')) {
    try {
        // Define E_CRITICAL if not available (for older PHP versions)
        if (!defined('E_CRITICAL')) {
            define('E_CRITICAL', 256);
        }
        
        $integrations = [];
        if (@class_exists('\\Sentry\\Integration\\FrameContextIntegration')) {
            $frameContextClass = '\\Sentry\\Integration\\FrameContextIntegration';
            $integrations[] = new $frameContextClass();
        }
        if (@class_exists('\\Sentry\\Integration\\RequestIntegration')) {
            $requestIntegrationClass = '\\Sentry\\Integration\\RequestIntegration';
            $integrations[] = new $requestIntegrationClass();
        }
        
        /** @phpstan-ignore-next-line */
        @\Sentry\init([
            'dsn' => $sentryDsn,
            'environment' => $environment,
            'traces_sample_rate' => (float)($_ENV['SENTRY_TRACES_SAMPLE_RATE'] ?? 0.1),
            'profiles_sample_rate' => (float)($_ENV['SENTRY_PROFILES_SAMPLE_RATE'] ?? 0.1),
            'error_types' => E_ERROR | (defined('E_CRITICAL') ? E_CRITICAL : 256) | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING,
            'integrations' => $integrations,
            'attach_stacktrace' => true,
            'max_breadcrumbs' => 50,
            'before_send' => function($event, $hint) {
                // Filter out sensitive data
                if (isset($event['request']['headers']['Authorization'])) {
                    unset($event['request']['headers']['Authorization']);
                }
                
                return $event;
            }
        ]);
        $sentryEnabled = true;
    } catch (\Throwable $e) {
        error_log('Sentry initialization failed: ' . $e->getMessage());
    }
}

// Monolog Configuration for structured logging
return [
    'sentry' => [
        'enabled' => $sentryEnabled && (bool)$sentryDsn,
        'dsn' => $sentryDsn,
        'environment' => $environment,
        'traces_sample_rate' => (float)($_ENV['SENTRY_TRACES_SAMPLE_RATE'] ?? 0.1),
    ],
    
    'logging' => [
        'default_channel' => 'stack',
        'channels' => [
            'stack' => [
                'driver' => 'stack',
                'channels' => ['single', 'sentry'],
            ],
            'single' => [
                'driver' => 'single',
                'path' => __DIR__ . '/../logs/smartsys.log',
                'level' => $environment === 'production' ? 'error' : 'debug',
                'bubble' => true,
            ],
            'sentry' => [
                'driver' => 'sentry',
                'level' => 'error',
                'bubble' => false,
            ],
            'security' => [
                'driver' => 'single',
                'path' => __DIR__ . '/../logs/security.log',
                'level' => 'info',
            ],
            'api' => [
                'driver' => 'single',
                'path' => __DIR__ . '/../logs/api.log',
                'level' => 'debug',
            ],
            'database' => [
                'driver' => 'single',
                'path' => __DIR__ . '/../logs/database.log',
                'level' => 'debug',
            ],
        ],
    ],
    
    'error_handling' => [
        // Report errors to Sentry
        'report_to_sentry' => $sentryEnabled && (bool)$sentryDsn,
        
        // Enable error logging to file
        'log_errors' => true,
        
        // Log file path
        'error_log' => __DIR__ . '/../logs/error.log',
        
        // Include stack traces in logs
        'include_stacktrace' => $environment !== 'production',
        
        // Display errors to user (only in dev)
        'display_errors' => $environment === 'development',
        
        // Error reporting level
        'error_reporting' => E_ALL,
    ],
    
    'performance' => [
        // Enable or disable performance monitoring
        'enabled' => true,
        
        // Sample rate for performance monitoring (0.0 - 1.0)
        'sample_rate' => (float)($_ENV['PERFORMANCE_SAMPLE_RATE'] ?? 0.1),
        
        // Track database queries
        'track_database_queries' => $environment !== 'production',
        
        // Track slow queries (time in ms)
        'slow_query_threshold' => 1000,
        
        // Track HTTP requests
        'track_http_requests' => true,
        
        // Track slow requests (time in ms)
        'slow_request_threshold' => 500,
    ],
    
    'alerting' => [
        // Enable or disable alerting
        'enabled' => true,
        
        // Alert channels
        'channels' => [
            'email' => [
                'enabled' => ($_ENV['ALERT_EMAIL_ENABLED'] ?? 'true') === 'true',
                'recipients' => explode(',', $_ENV['ALERT_EMAIL_RECIPIENTS'] ?? 'admin@smartsys.local'),
            ],
            'slack' => [
                'enabled' => ($_ENV['ALERT_SLACK_ENABLED'] ?? 'false') === 'true',
                'webhook_url' => $_ENV['ALERT_SLACK_WEBHOOK_URL'] ?? null,
                'channel' => $_ENV['ALERT_SLACK_CHANNEL'] ?? '#alerts',
            ],
        ],
        
        // Alert conditions
        'conditions' => [
            'high_error_rate' => [
                'threshold' => 10, // errors per minute
                'window' => 60, // seconds
            ],
            'slow_response' => [
                'threshold' => 5000, // ms
            ],
            'database_error' => [
                'enabled' => true,
            ],
            'authentication_failure' => [
                'enabled' => true,
            ],
        ],
    ],
];
