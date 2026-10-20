<?php

declare(strict_types=1);

namespace App\Security;

use DomainException;

/** Defines the sale states allowed for payment, returns, editing, and transitions. */
final class SaleWorkflowPolicy
{
    private const PAYABLE_STATUSES = ['pending_payment', 'partial', 'due'];
    private const EDITABLE_STATUSES = ['draft', 'pending_approval', 'rejected'];
    private const APPROVAL_PENDING_STATUS = 'pending_approval';

    public static function isApprovalPending(string $status): bool
    {
        return strtolower(trim($status)) === self::APPROVAL_PENDING_STATUS;
    }

    public static function assertPaymentAllowed(string $status): void
    {
        if (!in_array(strtolower(trim($status)), self::PAYABLE_STATUSES, true)) {
            throw new DomainException('لا يمكن تسجيل دفعة على فاتورة غير معتمدة أو ملغاة.');
        }
    }

    public static function assertReturnAllowed(string $status): void
    {
        if (in_array(strtolower(trim($status)), [
            'draft',
            'pending',
            'pending_approval',
            'awaiting_approval',
            'rejected',
            'canceled',
            'cancelled',
        ], true)) {
            throw new DomainException('لا يمكن إنشاء مرتجع لفاتورة مبيعات غير معتمدة أو ملغاة.');
        }
    }

    public static function assertSaleEditable(string $status, bool $hasPostedJournal): void
    {
        if (!in_array(strtolower(trim($status)), self::EDITABLE_STATUSES, true) || $hasPostedJournal) {
            throw new DomainException('لا يمكن تعديل فاتورة تم اعتمادها أو ترحيلها محاسبياً.');
        }
    }

}
