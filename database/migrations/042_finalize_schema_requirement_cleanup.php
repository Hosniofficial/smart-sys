<?php

declare(strict_types=1);

/**
 * Removes the last requested column comment and standardizes permission descriptions.
 */
final class FinalizeSchemaRequirementCleanup
{
    private const PERMISSION_DESCRIPTIONS = [
        'accounting.owner_contribution.create' => [
            'إنشاء وتسجيل مساهمة مالك (رأس مال، قرض مالك، رصيد افتتاحي)',
            'Create and record an owner contribution (capital, owner loan, opening balance)',
        ],
        'accounting.owner_contribution.view' => [
            'عرض وقراءة سجلات مساهمات المالك',
            'View owner contribution records',
        ],
        'accounting.owner_contribution.void' => [
            'إلغاء مساهمة مالك موجودة (عكس القيد المحاسبي)',
            'Void an existing owner contribution (reverse the journal entry)',
        ],
        'product.purchase_price.view' => [
            'عرض سعر شراء المنتج ومعلومات التكلفة',
            'View product purchase prices and cost information',
        ],
        'sale.discount' => [
            'السماح بتطبيق خصم على البيع ضمن حدود المنتج',
            'Apply sales discounts within product limits',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function up(): void
    {
        $this->assertCostCenterColumnCanBeUpdated();
        $this->assertPermissionDescriptionsCanBeUpdated();
        $this->removeCostCenterColumnComment();
        $this->standardizePermissionDescriptions();
    }

    public function down(): void
    {
        throw new RuntimeException('Migration 042 is forward-only; restoring comments or prior descriptions requires a reviewed decision.');
    }

    private function assertCostCenterColumnCanBeUpdated(): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute(['cash_vouchers', 'cost_center_id']);
        $column = $statement->fetch(PDO::FETCH_ASSOC);
        if ($column === false) {
            throw new RuntimeException('Missing cash_vouchers.cost_center_id.');
        }

        $type = strtolower((string) $column['COLUMN_TYPE']);
        $comment = (string) $column['COLUMN_COMMENT'];
        $nullDefault = $column['COLUMN_DEFAULT'] === null
            || strtoupper((string) $column['COLUMN_DEFAULT']) === 'NULL';
        if (!preg_match('/^int(?:\(\d+\))?$/', $type)
            || $column['IS_NULLABLE'] !== 'YES'
            || !$nullDefault
            || (string) $column['EXTRA'] !== ''
            || !in_array($comment, ['', 'مركز التكلفة (اختياري)'], true)
        ) {
            throw new RuntimeException('Unexpected metadata for cash_vouchers.cost_center_id; refusing to alter it.');
        }
    }

    private function assertPermissionDescriptionsCanBeUpdated(): void
    {
        $statement = $this->pdo->prepare(
            'SELECT description FROM permissions WHERE name = ?'
        );
        foreach (self::PERMISSION_DESCRIPTIONS as $name => [$arabicDescription, $englishDescription]) {
            $statement->execute([$name]);
            $description = $statement->fetchColumn();
            if ($description === false
                || !in_array($description, [$arabicDescription, $englishDescription], true)
            ) {
                throw new RuntimeException(
                    "Missing or unexpected description for permission {$name}; refusing to overwrite it."
                );
            }
        }
    }

    private function removeCostCenterColumnComment(): void
    {
        $this->pdo->exec(
            "ALTER TABLE `cash_vouchers`
             MODIFY COLUMN `cost_center_id` INT NULL DEFAULT NULL COMMENT ''"
        );
    }

    private function standardizePermissionDescriptions(): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE permissions SET description = ? WHERE name = ?'
        );
        foreach (self::PERMISSION_DESCRIPTIONS as $name => [, $englishDescription]) {
            $statement->execute([$englishDescription, $name]);
        }
    }
}
