<?php

namespace App\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Creates channel-specific Monolog loggers with environment-based handlers and request context.
 */
class MonologHandler
{
    private static $instances = [];
    private $logger;
    private $env;
    private $logLevel;

    private static $levelMap = [
        'debug' => Logger::DEBUG,
        'info' => Logger::INFO,
        'warning' => Logger::WARNING,
        'error' => Logger::ERROR,
        'critical' => Logger::CRITICAL,
        'alert' => Logger::ALERT,
        'emergency' => Logger::EMERGENCY,
    ];

    /** Create a logger channel using APP_ENV and LOG_LEVEL configuration. */
    public function __construct($channel = 'erp')
    {
        $this->env = getenv('APP_ENV') ?: 'production';
        $this->logLevel = getenv('LOG_LEVEL') ?: ($this->env === 'production' ? 'error' : 'debug');

        $this->logger = new Logger($channel);
        $this->setupHandlers();
    }

    /** Return the cached logger wrapper for a channel. */
    public static function getInstance($channel = 'erp'): self
    {
        if (!isset(self::$instances[$channel])) {
            self::$instances[$channel] = new self($channel);
        }
        return self::$instances[$channel];
    }

    /** Attach environment-specific file/console handlers and request context. */
    private function setupHandlers(): void
    {
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );

        if ($this->env === 'production') {
            $logFile = __DIR__ . '/../../../../logs/erp-error.log';
            $errorHandler = new RotatingFileHandler($logFile, 30, Logger::ERROR);
            $errorHandler->setFormatter($formatter);
            $this->logger->pushHandler($errorHandler);

            $criticalFile = __DIR__ . '/../../../../logs/erp-critical.log';
            $criticalHandler = new RotatingFileHandler($criticalFile, 30, Logger::CRITICAL);
            $criticalHandler->setFormatter($formatter);
            $this->logger->pushHandler($criticalHandler);

        } else {
            $logFile = __DIR__ . '/../../../../logs/erp-debug.log';
            $debugHandler = new RotatingFileHandler($logFile, 7, Logger::DEBUG);
            $debugHandler->setFormatter($formatter);
            $this->logger->pushHandler($debugHandler);

            $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
            $consoleHandler->setFormatter($formatter);
            $this->logger->pushHandler($consoleHandler);
        }

        $this->logger->pushProcessor(function ($record) {
            $record['extra']['tenant_id'] = $_SERVER['HTTP_X_TENANT_ID'] ?? null;
            $record['extra']['user_id'] = $_SESSION['user_id'] ?? null;
            $record['extra']['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $record['extra']['request_uri'] = $_SERVER['REQUEST_URI'] ?? 'unknown';
            $record['extra']['method'] = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
            return $record;
        });
    }

    /** Compare a requested level with the configured threshold. */
    private function shouldLog($level)
    {
        if (!isset(self::$levelMap[$level]) || !isset(self::$levelMap[$this->logLevel])) {
            return false;
        }

        return self::$levelMap[$level] >= self::$levelMap[$this->logLevel];
    }

    /** Add environment metadata and write a message when it meets the configured threshold. */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $context['env'] = $this->env;
        $context['timestamp'] = date('Y-m-d H:i:s');

        $this->logger->log(self::$levelMap[$level], $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** Log an authentication event at info level. */
    public function auth(string $action, ?int $userId = null, ?int $tenantId = null, array $context = []): void
    {
        $this->info("Auth: {$action}", array_merge([
            'action' => $action,
            'user_id' => $userId,
            'tenant_id' => $tenantId
        ], $context));
    }

    /** Log an API request at info level. */
    public function api(string $method, string $endpoint, ?int $userId = null, array $context = []): void
    {
        $this->info("API: {$method} {$endpoint}", array_merge([
            'method' => $method,
            'endpoint' => $endpoint,
            'user_id' => $userId
        ], $context));
    }

    /** Log a database operation at debug level. */
    public function db(string $operation, string $table, array $context = []): void
    {
        $this->debug("DB: {$operation} on {$table}", array_merge([
            'operation' => $operation,
            'table' => $table
        ], $context));
    }

    /** Log a business event at info level. */
    public function business(string $event, array $context = []): void
    {
        $this->info("Business: {$event}", $context);
    }

    /** Log performance metrics outside production. */
    public function performance(string $operation, float $duration, array $context = []): void
    {
        if ($this->env !== 'production') {
            $this->debug("Performance: {$operation}", array_merge([
                'operation' => $operation,
                'duration_ms' => $duration,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ], $context));
        }
    }

    /** Log a security event at warning level, subject to the configured threshold. */
    public function security(string $event, array $context = []): void
    {
        $this->warning("Security: {$event}", array_merge([
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => time(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ], $context));
    }

    /** Return the underlying Monolog logger, bypassing this wrapper's context enrichment. */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /** Create the repository log directory when it is absent. */
    public static function ensureLogsDirectory(): void
    {
        $logsDir = __DIR__ . '/../../../../logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
    }
}

MonologHandler::ensureLogsDirectory();
