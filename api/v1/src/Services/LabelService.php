<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Maps transaction reference types and statuses to Arabic or English labels.
 */
class LabelService
{
    /**
     * Translate a reference type; null/empty values return an empty label.
     * Only the exact locale 'ar' selects Arabic; other values select English.
     */
    public static function refLabel(?string $type, string $locale = 'ar'): string
    {
        if (!$type) {
            return '';
        }

        $t = strtolower($type);

        $ar = [
            'sale'                  => 'فاتورة بيع',
            'sales'                 => 'فاتورة بيع',
            'purchase'              => 'فاتورة شراء',
            'purchases'             => 'فاتورة شراء',
            'sales_return'          => 'إشعار دائن - مرتجع بيع',
            'sale_return'           => 'إشعار دائن - مرتجع بيع',
            'return_sale'           => 'إشعار دائن - مرتجع بيع',
            'return'                => 'إشعار دائن',
            'purchase_return'       => 'إشعار مدين - مرتجع مشتريات',
            'return_purchase'       => 'إشعار مدين - مرتجع مشتريات',
            'receipt'               => 'سند قبض',
            'customer_payment'     => 'سند قبض',
            'customer_payment_reclassification' => 'تصحيح ترحيل سند قبض',
            'debt_payment'         => 'سند قبض',
            'payment'               => 'سند دفع',
            'return_payment'        => 'صرف مرتجع للعميل',
            'refund'                => 'سند صرف',
            'sales_return_refund'   => 'استرداد عميل (مرتجع)',
            'purchase_return_refund' => 'استرجاع مورد (مرتجع)',
            'cash_voucher'          => 'سند نقدي',
            'journal'               => 'قيد يومية',
        ];

        $en = [
            'sale'                  => 'Sales Invoice',
            'sales'                 => 'Sales Invoice',
            'purchase'              => 'Purchase Invoice',
            'purchases'             => 'Purchase Invoice',
            'sales_return'          => 'Credit Note - Sales Return',
            'sale_return'           => 'Credit Note - Sales Return',
            'return_sale'           => 'Credit Note - Sales Return',
            'return'                => 'Credit Note',
            'purchase_return'       => 'Debit Note - Purchase Return',
            'return_purchase'       => 'Debit Note - Purchase Return',
            'receipt'               => 'Receipt',
            'customer_payment'     => 'Receipt',
            'customer_payment_reclassification' => 'Receipt Posting Correction',
            'debt_payment'         => 'Receipt',
            'payment'               => 'Payment',
            'return_payment'        => 'Customer Return Refund',
            'refund'                => 'Refund',
            'sales_return_refund'   => 'Customer Refund (Return)',
            'purchase_return_refund' => 'Supplier Refund (Return)',
            'cash_voucher'          => 'Cash Voucher',
            'journal'               => 'Journal Entry',
        ];

        $map = $locale === 'ar' ? $ar : $en;

        return $map[$t] ?? ($locale === 'ar' ? 'مرجع' : 'Reference');
    }

    /**
     * Translate a status code; null/empty values return an empty label.
     * Only the exact locale 'ar' selects Arabic; other values select English.
     */
    public static function statusLabel(?string $code, string $locale = 'ar'): string
    {
        if (!$code) {
            return '';
        }

        $c = strtolower($code);

        $ar = [
            'paid'              => 'مدفوعة',
            'partially_paid'    => 'مدفوعة جزئياً',
            'partial'           => 'مدفوعة جزئياً',
            'unpaid'            => 'غير مدفوعة',
            'pending'           => 'قيد الانتظار',
            'pending_payment'   => 'غير مدفوعة',
            'posted'            => 'مُرحَّل',
            'draft'             => 'مسودة',
            'canceled'          => 'ملغاة',
            'cancelled'         => 'ملغاة',
            'rejected'          => 'مرفوضة',
            'completed'         => 'مكتملة',
            'due'               => 'مستحقة',
            'returned'          => 'مرتجعة',
            'closed_by_return'  => 'مرتجعة',
            'closedbyreturn'    => 'مرتجعة',
            'settled_by_return' => 'مسوّاة بإشعار دائن',
            'settledbyreturn'   => 'مسوّاة بإشعار دائن',
            'settled_by_credit' => 'مسوّاة بمرتجع',
            'settled_mixed'     => 'مسوّاة نقدي/إشعار دائن',
            'approved'          => 'معتمدة',
            'approval'          => 'تحت الاعتماد',
            'pending_approval'  => 'تحت الاعتماد',
        ];

        $en = [
            'paid'              => 'Paid',
            'partially_paid'    => 'Partially Paid',
            'partial'           => 'Partially Paid',
            'unpaid'            => 'Unpaid',
            'pending'           => 'Pending',
            'pending_payment'   => 'Pending Payment',
            'posted'            => 'Posted',
            'draft'             => 'Draft',
            'canceled'          => 'Canceled',
            'cancelled'         => 'Canceled',
            'rejected'          => 'Rejected',
            'completed'         => 'Completed',
            'due'               => 'Due',
            'returned'          => 'Returned',
            'closed_by_return'  => 'Returned',
            'closedbyreturn'    => 'Returned',
            'settled_by_return' => 'Settled by Credit Note',
            'settledbyreturn'   => 'Settled by Credit Note',
            'settled_by_credit' => 'Offset by Return',
            'settled_mixed'     => 'Settled by Mixed Cash/Credit',
            'approved'          => 'Approved',
            'approval'          => 'Pending Approval',
            'pending_approval'  => 'Pending Approval',
        ];

        $map = $locale === 'ar' ? $ar : $en;

        return $map[$c] ?? ($locale === 'ar' ? 'غير معروف' : 'Unknown');
    }
}
