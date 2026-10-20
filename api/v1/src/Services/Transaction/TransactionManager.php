<?php

namespace App\Services\Transaction;

use PDO;
use Throwable;
use App\Services\MonologHandler;

/**
 * Coordinates a PDO transaction across nested callback executions and logs outcomes.
 */
class TransactionManager
{
    private PDO $pdo;
    private MonologHandler $logger;
    private int $depth = 0; // لدعم nested transactions

    public function __construct(PDO $pdo, string $logChannel = 'transactions')
    {
        $this->pdo = $pdo;
        $this->logger = MonologHandler::getInstance($logChannel);
    }

    /**
     * تنفيذ عملية بكاملها ضمن transaction واحد
     *
     * @param callable $callback الدالة التي تحتوي على المنطق
     * @param string $operationName اسم العملية للـ logging
     * @param array $context بيانات إضافية للـ logging
     * @return mixed نتيجة الـ callback
     * @throws Throwable
     */
    public function execute(callable $callback, string $operationName = '', array $context = []): mixed
    {
        $startTime = microtime(true);

        if ($this->depth === 0) {
            $this->pdo->beginTransaction();
            $this->logger->info("Transaction started: $operationName", $context);
        }

        $this->depth++;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->depth--;

            if ($this->depth === 0) {
                $this->rollBackIfActive($operationName, $context);
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                // Business rule violations are expected; log unexpected failures with a trace.
                $isExpected = $e instanceof \Exception
                    && !($e instanceof \PDOException)
                    && !($e instanceof \RuntimeException);

                if ($isExpected) {
                    $this->logger->warning("Transaction rolled back: $operationName", array_merge($context, [
                        'duration_ms' => $duration,
                        'error'       => $e->getMessage(),
                    ]));
                } else {
                    $this->logger->error("Transaction rolled back: $operationName", array_merge($context, [
                        'duration_ms' => $duration,
                        'error'       => $e->getMessage(),
                        'code'        => $e->getCode(),
                        'trace'       => $e->getTraceAsString(),
                    ]));
                }
            }

            throw $e;
        }

        $this->depth--;

        if ($this->depth === 0) {
            try {
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->rollBackIfActive($operationName, $context);
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $this->logger->error("Transaction commit failed: $operationName", array_merge($context, [
                    'duration_ms' => $duration,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString(),
                ]));

                throw $e;
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info("Transaction committed: $operationName", array_merge($context, [
                'duration_ms' => $duration,
                'status' => 'success'
            ]));
        }

        return $result;
    }

    private function rollBackIfActive(string $operationName, array $context): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } else {
                $this->logger->warning("Transaction already closed before rollback: $operationName", $context);
            }
        } catch (Throwable $rollbackError) {
            $this->logger->error("Transaction rollback failed: $operationName", array_merge($context, [
                'error' => $rollbackError->getMessage(),
                'code' => $rollbackError->getCode(),
                'trace' => $rollbackError->getTraceAsString(),
            ]));
        }
    }

    /**
     * الفحص إذا كانت هناك transaction مفتوحة
     */
    public function isActive(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * الحصول على عمق الـ nesting الحالي
     */
    public function getDepth(): int
    {
        return $this->depth;
    }
}
