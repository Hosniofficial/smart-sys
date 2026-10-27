<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * ServiceFactory
 *
 * Creates request-scoped domain services with tenant and user context.
 *
 * Why a factory instead of a DI container singleton?
 *   PurchaseService, ReturnService, and SalesService require per-request context
 *   (tenantId, userId) that is only known at runtime. A factory is the correct
 *   pattern for request-scoped objects.
 *
 * Usage in a handler:
 *   $factory = new ServiceFactory($this->db);
 *   $svc     = $factory->purchase($tenantId, $userId);
 *   $svc->createJournalEntry(...);
 */
class ServiceFactory
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Request-scoped services (new instance per call — carry tenantId + userId)
    // -------------------------------------------------------------------------

    /**
     * Create a PurchaseService for the given request context.
     */
    public function purchase(int $tenantId, ?int $userId = null): PurchaseService
    {
        return new PurchaseService($this->db, $tenantId, $userId);
    }

    /**
     * Create a ReturnService for the given request context.
     */
    public function returns(int $tenantId, ?int $userId = null): ReturnService
    {
        return new ReturnService($this->db, $tenantId, $userId);
    }

    /**
     * Create a SalesService for the given request context.
     * tenantId is passed for consistency with PurchaseService/ReturnService,
     * and as default context for logAudit. createSale() still sets tenantId from $data.
     */
    public function sales(int $tenantId, ?int $userId = null): SalesService
    {
        return new SalesService($this->db, $userId ?? 1, $tenantId);
    }

    /**
     * Create a SaleApprovalService for the given request context.
     */
    public function saleApproval(int $tenantId, ?int $userId = null): SaleApprovalService
    {
        return new SaleApprovalService($this->db, $userId ?? 1, $tenantId);
    }

    /**
     * Create a SalePaymentService for the given request context.
     */
    public function salePayment(?int $userId = null): SalePaymentService
    {
        return new SalePaymentService($this->db, $userId ?? 1);
    }

    /**
     * Create a SaleCreationService for the given request context.
     */
    public function saleCreation(int $tenantId, ?int $userId = null): SaleCreationService
    {
        return new SaleCreationService($this->db, $tenantId, $userId ?? 1);
    }

    /**
     * Create an EmailVerificationService (stateless — no tenantId/userId needed).
     */
    public function emailVerification(): \App\Services\EmailVerificationService
    {
        return new \App\Services\EmailVerificationService($this->db);
    }
}
