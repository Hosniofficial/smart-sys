<?php

declare(strict_types=1);

/** Give every permission in the current catalog an Arabic display name. */
final class AddArabicPermissionNames
{
    public const REQUIRES_BACKUP_CONFIRMATION = true;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function up(): void
    {
        $displayNames = [
            'accounting.je.create' => 'إنشاء القيود اليومية',
            'accounting.je.delete' => 'حذف القيود اليومية',
            'accounting.je.reverse' => 'عكس القيود اليومية',
            'accounting.opening_balance.commit' => 'ترحيل الرصيد الافتتاحي',
            'accounting.period.close.override' => 'تجاوز موانع إغلاق الفترة المحاسبية',
            'accounting.period.manage' => 'إدارة الفترات المحاسبية',
            'accounting.view' => 'عرض الحسابات والتقارير المحاسبية',
            'accounting.owner_contribution.create' => 'إنشاء مساهمات المالك',
            'accounting.owner_contribution.view' => 'عرض مساهمات المالك',
            'accounting.owner_contribution.void' => 'إلغاء مساهمات المالك',
            'branch.create' => 'إنشاء الفروع',
            'branch.delete' => 'تعطيل الفروع وحذفها',
            'branch.edit' => 'تعديل بيانات الفروع',
            'branch.view' => 'عرض الفروع',
            'category.create' => 'إنشاء تصنيفات المنتجات',
            'category.delete' => 'حذف تصنيفات المنتجات',
            'category.edit' => 'تعديل تصنيفات المنتجات',
            'category.view' => 'عرض تصنيفات المنتجات',
            'customer.create' => 'إنشاء العملاء',
            'customer.delete' => 'حذف العملاء',
            'customer.edit' => 'تعديل بيانات العملاء',
            'customer.view' => 'عرض العملاء',
            'supplier.create' => 'إنشاء الموردين',
            'supplier.delete' => 'حذف الموردين',
            'supplier.edit' => 'تعديل بيانات الموردين',
            'supplier.view' => 'عرض الموردين',
            'document.delete' => 'حذف المستندات',
            'document.edit' => 'تعديل المستندات',
            'document.upload' => 'رفع المستندات',
            'document.view' => 'عرض المستندات',
            'inventory.adjust' => 'تسوية كميات المخزون',
            'inventory.transfer' => 'تحويل المخزون',
            'inventory.view' => 'عرض المخزون',
            'permission.assign' => 'إسناد الصلاحيات',
            'permission.view' => 'عرض الصلاحيات',
            'pos.session.close' => 'إغلاق جلسات نقاط البيع',
            'pos.session.manage' => 'إدارة جلسات نقاط البيع',
            'pos.session.open' => 'فتح جلسات نقاط البيع',
            'pos.terminal.manage' => 'إدارة أجهزة نقاط البيع',
            'product.create' => 'إنشاء المنتجات',
            'product.delete' => 'حذف المنتجات',
            'product.edit' => 'تعديل المنتجات',
            'product.view' => 'عرض المنتجات',
            'product.purchase_price.view' => 'عرض أسعار شراء المنتجات والتكلفة',
            'purchase.create' => 'إنشاء فواتير المشتريات',
            'purchase.delete' => 'حذف فواتير المشتريات',
            'purchase.edit' => 'تعديل فواتير المشتريات',
            'purchase.payment.create' => 'تسجيل مدفوعات فواتير المشتريات',
            'purchase.view' => 'عرض فواتير المشتريات',
            'report.financial' => 'عرض التقارير المالية',
            'report.inventory' => 'عرض تقارير المخزون',
            'report.purchases' => 'عرض تقارير المشتريات',
            'report.sales' => 'عرض تقارير المبيعات',
            'return.approve' => 'اعتماد طلبات المرتجعات',
            'return.create' => 'إنشاء مرتجعات المبيعات والمشتريات',
            'return.delete' => 'حذف المرتجعات أو إلغاؤها',
            'return.view' => 'عرض المرتجعات',
            'role.create' => 'إنشاء الأدوار',
            'role.delete' => 'حذف الأدوار',
            'role.edit' => 'تعديل الأدوار',
            'role.view' => 'عرض الأدوار',
            'sales.approval.approve' => 'اعتماد فواتير المبيعات',
            'sales.approval.reject' => 'رفض فواتير المبيعات',
            'sales.approval.view' => 'عرض موافقات المبيعات',
            'sale.create' => 'إنشاء فواتير المبيعات',
            'sale.discount' => 'تطبيق خصومات على المبيعات',
            'sale.edit' => 'تعديل فواتير المبيعات',
            'sale.payment.create' => 'تسجيل مدفوعات فواتير المبيعات',
            'sale.view' => 'عرض فواتير المبيعات',
            'sale.void' => 'إلغاء فواتير المبيعات',
            'payment_method.manage' => 'إدارة طرق الدفع',
            'payment_method.view' => 'عرض إعدادات طرق الدفع',
            'settings.manage' => 'إدارة إعدادات النظام',
            'settings.view' => 'عرض إعدادات النظام',
            'user.create' => 'إنشاء حسابات المستخدمين',
            'user.delete' => 'حذف حسابات المستخدمين',
            'user.edit' => 'تعديل بيانات المستخدمين',
            'user.view' => 'عرض بيانات المستخدمين',
            'voucher.create' => 'إنشاء سندات القبض والصرف',
            'voucher.delete' => 'حذف سندات القبض والصرف',
            'voucher.edit' => 'تعديل سندات القبض والصرف',
            'voucher.view' => 'عرض سندات القبض والصرف',
            'warranty.create' => 'إنشاء طلبات الضمان',
            'warranty.delete' => 'حذف مرفقات الضمان',
            'warranty.edit' => 'تعديل طلبات الضمان وحالتها',
            'warranty.view' => 'عرض طلبات الضمان',
        ];

        $placeholders = implode(',', array_fill(0, count($displayNames), '?'));
        $unmapped = $this->pdo->prepare(
            "SELECT DISTINCT name FROM permissions
             WHERE (tenant_id IS NULL OR tenant_id > 0)
               AND name NOT LIKE 'platform.%'
               AND name NOT IN ({$placeholders})"
        );
        $unmapped->execute(array_keys($displayNames));
        $unmappedNames = $unmapped->fetchAll(PDO::FETCH_COLUMN);
        if ($unmappedNames !== []) {
            throw new RuntimeException(
                'Arabic display names are missing for permissions: ' . implode(', ', $unmappedNames)
            );
        }

        $update = $this->pdo->prepare(
            'UPDATE permissions
             SET display_name = ?
             WHERE name = ? AND (tenant_id IS NULL OR tenant_id > 0)'
        );
        foreach ($displayNames as $name => $displayName) {
            $update->execute([$displayName, $name]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Migration 036 is intentionally irreversible.');
    }
}
