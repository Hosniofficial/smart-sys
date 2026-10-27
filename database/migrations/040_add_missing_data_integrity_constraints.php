<?php

declare(strict_types=1);

/**
 * Aligns reviewed reference columns, preserves orphaned history, and adds missing FKs.
 */
final class AddMissingDataIntegrityConstraints
{
    private const REFERENCES = [
        ['account_device_associations', 'tenant_id', 'tenants', 'RESTRICT'],
        ['account_device_associations', 'user_id', 'users', 'RESTRICT'],
        ['account_device_associations', 'device_fingerprint_id', 'device_fingerprints', 'RESTRICT'],
        ['cashier_sessions', 'terminal_id', 'terminals', 'SET NULL'],
        ['cashier_sessions', 'shift_id', 'cashier_shifts', 'SET NULL'],
        ['cash_transactions', 'return_id', 'returns', 'SET NULL'],
        ['cash_transactions', 'branch_id', 'branches', 'SET NULL'],
        ['cash_transactions', 'session_id', 'cashier_sessions', 'SET NULL'],
        ['cash_vouchers', 'created_by', 'users', 'SET NULL'],
        ['cost_centers', 'tenant_id', 'tenants', 'RESTRICT'],
        ['customers', 'branch_id', 'branches', 'SET NULL'],
        ['customer_payment_allocations', 'tenant_id', 'tenants', 'RESTRICT'],
        ['email_verification_tokens', 'tenant_id', 'tenants', 'RESTRICT'],
        ['email_verification_tokens', 'user_id', 'users', 'RESTRICT'],
        ['inventory_cost_snapshot', 'tenant_id', 'tenants', 'RESTRICT'],
        ['inventory_cost_snapshot', 'product_id', 'products', 'RESTRICT'],
        ['inventory_cost_snapshot', 'branch_id', 'branches', 'RESTRICT'],
        ['inventory_transactions', 'unit_id', 'units', 'SET NULL'],
        ['inventory_transactions', 'journal_entry_id', 'journal_entries', 'SET NULL'],
        ['invoice_approvals', 'tenant_id', 'tenants', 'RESTRICT'],
        ['invoice_approvals', 'sale_id', 'sales', 'RESTRICT'],
        ['invoice_approvals', 'action_by', 'users', 'SET NULL'],
        ['payment_applications', 'tenant_id', 'tenants', 'RESTRICT'],
        ['payment_applications', 'payment_id', 'payments', 'SET NULL'],
        ['payment_applications', 'created_by', 'users', 'SET NULL'],
        ['payment_idempotency_keys', 'tenant_id', 'tenants', 'RESTRICT'],
        ['payment_transactions', 'tenant_id', 'tenants', 'RESTRICT'],
        ['payment_transactions', 'user_id', 'users', 'RESTRICT'],
        ['payment_transactions', 'subscription_id', 'subscriptions', 'RESTRICT'],
        ['permission_overrides', 'tenant_id', 'tenants', 'RESTRICT'],
        ['permission_overrides', 'permission_id', 'permissions', 'RESTRICT'],
        ['permission_overrides', 'created_by', 'users', 'RESTRICT'],
        ['product_activation_log', 'tenant_id', 'tenants', 'RESTRICT'],
        ['product_activation_log', 'product_id', 'products', 'RESTRICT'],
        ['product_activation_log', 'branch_id', 'branches', 'RESTRICT'],
        ['product_activation_log', 'gl_journal_entry_id', 'journal_entries', 'SET NULL'],
        ['product_activation_log', 'user_id', 'users', 'RESTRICT'],
        ['products', 'default_category_gl_account_id', 'accounts', 'SET NULL'],
        ['product_branch_gl_mapping', 'tenant_id', 'tenants', 'RESTRICT'],
        ['product_branch_gl_mapping', 'inventory_gl_account_id', 'accounts', 'RESTRICT'],
        ['product_branch_gl_mapping', 'purchase_gl_account_id', 'accounts', 'RESTRICT'],
        ['product_branch_gl_mapping', 'cogs_gl_account_id', 'accounts', 'RESTRICT'],
        ['product_branch_gl_mapping', 'created_by_user_id', 'users', 'SET NULL'],
        ['product_branch_gl_mapping', 'updated_by_user_id', 'users', 'SET NULL'],
        ['repair_orders', 'tenant_id', 'tenants', 'RESTRICT'],
        ['repair_orders', 'branch_id', 'branches', 'RESTRICT'],
        ['repair_orders', 'customer_id', 'customers', 'RESTRICT'],
        ['repair_orders', 'labor_product_id', 'products', 'SET NULL'],
        ['repair_orders', 'invoice_id', 'sales', 'SET NULL'],
        ['repair_orders', 'received_by', 'users', 'SET NULL'],
        ['repair_orders', 'delivered_by', 'users', 'SET NULL'],
        ['repair_order_parts', 'tenant_id', 'tenants', 'RESTRICT'],
        ['repair_order_parts', 'product_id', 'products', 'RESTRICT'],
        ['repair_order_parts', 'unit_id', 'units', 'RESTRICT'],
        ['return_credit_allocations', 'tenant_id', 'tenants', 'RESTRICT'],
        ['return_credit_allocations', 'created_by', 'users', 'SET NULL'],
        ['return_items', 'return_id', 'returns', 'RESTRICT'],
        ['returns', 'payment_method_id', 'payment_methods', 'SET NULL'],
        ['returns', 'created_by', 'users', 'RESTRICT'],
        ['sales_items', 'created_by', 'users', 'SET NULL'],
        ['sales_items', 'updated_by', 'users', 'SET NULL'],
        ['sales', 'approved_by', 'users', 'SET NULL'],
        ['sales', 'journal_entry_id', 'journal_entries', 'SET NULL'],
        ['sales', 'session_id', 'cashier_sessions', 'SET NULL'],
        ['payments', 'session_id', 'cashier_sessions', 'SET NULL'],
        ['payments', 'branch_id', 'branches', 'SET NULL'],
        ['purchases', 'journal_entry_id', 'journal_entries', 'SET NULL'],
        ['users', 'created_by', 'users', 'SET NULL'],
        ['sales_price_campaigns', 'tenant_id', 'tenants', 'RESTRICT'],
        ['sales_price_campaigns', 'branch_id', 'branches', 'RESTRICT'],
        ['sales_price_campaigns', 'created_by', 'users', 'RESTRICT'],
        ['sales_price_campaign_items', 'tenant_id', 'tenants', 'RESTRICT'],
        ['sales_price_campaign_items', 'product_id', 'products', 'RESTRICT'],
        ['sales_price_campaign_items', 'unit_id', 'units', 'RESTRICT'],
        ['sales_quotations', 'tenant_id', 'tenants', 'RESTRICT'],
        ['sales_quotations', 'branch_id', 'branches', 'RESTRICT'],
        ['sales_quotations', 'customer_id', 'customers', 'SET NULL'],
        ['sales_quotations', 'created_by', 'users', 'RESTRICT'],
        ['security_events', 'tenant_id', 'tenants', 'SET NULL'],
        ['security_events', 'user_id', 'users', 'SET NULL'],
        ['security_events', 'target_user_id', 'users', 'SET NULL'],
        ['subscription_attempts', 'tenant_id', 'tenants', 'SET NULL'],
        ['subscription_attempts', 'user_id', 'users', 'SET NULL'],
        ['terminals', 'tenant_id', 'tenants', 'RESTRICT'],
        ['terminals', 'branch_id', 'branches', 'SET NULL'],
        ['terminals', 'created_by', 'users', 'SET NULL'],
        ['units', 'tenant_id', 'tenants', 'SET NULL'],
        ['warranty_attachments', 'tenant_id', 'tenants', 'RESTRICT'],
        ['warranty_attachments', 'uploaded_by', 'users', 'RESTRICT'],
        ['warranty_items', 'tenant_id', 'tenants', 'RESTRICT'],
        ['warranty_items', 'product_id', 'products', 'RESTRICT'],
        ['warranty_notes', 'tenant_id', 'tenants', 'RESTRICT'],
        ['warranty_notes', 'created_by', 'users', 'RESTRICT'],
        ['warranty_requests', 'tenant_id', 'tenants', 'RESTRICT'],
        ['warranty_requests', 'customer_id', 'customers', 'SET NULL'],
        ['warranty_requests', 'invoice_id', 'sales', 'SET NULL'],
        ['warranty_requests', 'created_by', 'users', 'RESTRICT'],
        ['warranty_requests', 'assigned_to', 'users', 'SET NULL'],
    ];

    private const TYPE_ALIGNED_COLUMNS = [
        ['account_device_associations', 'tenant_id'],
        ['account_device_associations', 'user_id'],
        ['customers', 'branch_id'],
        ['email_verification_tokens', 'tenant_id'],
        ['email_verification_tokens', 'user_id'],
        ['payment_transactions', 'tenant_id'],
        ['payment_transactions', 'user_id'],
        ['payment_transactions', 'subscription_id'],
        ['security_events', 'tenant_id'],
        ['security_events', 'user_id'],
        ['security_events', 'target_user_id'],
        ['subscription_attempts', 'tenant_id'],
        ['subscription_attempts', 'user_id'],
        ['terminals', 'tenant_id'],
        ['terminals', 'created_by'],
        ['warranty_attachments', 'tenant_id'],
        ['warranty_attachments', 'uploaded_by'],
        ['warranty_items', 'tenant_id'],
        ['warranty_items', 'product_id'],
        ['warranty_notes', 'tenant_id'],
        ['warranty_notes', 'created_by'],
        ['warranty_requests', 'tenant_id'],
        ['warranty_requests', 'customer_id'],
        ['warranty_requests', 'invoice_id'],
        ['warranty_requests', 'created_by'],
        ['warranty_requests', 'assigned_to'],
    ];

    private const BIGINT_UNSIGNED_COLUMNS = [
        ['cashier_sessions', 'shift_id'],
    ];

    private const COMMENT_TABLES = [
        'branch_products',
        'inventory_cost_snapshot',
        'journal_entries',
        'migrations',
        'notifications',
        'notification_batches',
        'notification_preferences',
        'owner_contributions',
        'payments',
        'payment_applications',
        'payment_methods',
        'products',
        'product_activation_log',
        'purchases',
        'returns',
        'security_events',
        'stock_transfers',
    ];

    private const ALLOWED_ORPHANS = [
        'inventory_transactions.journal_entry_id' => [
            44 => 43,
            46 => 44,
        ],
        'security_events.user_id' => [
            30 => 3,
        ],
        'sales.journal_entry_id' => [
            11 => 43,
            12 => 44,
        ],
        'cashier_sessions.shift_id' => [
            11 => 1,
        ],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function up(): void
    {
        $this->assertMySqlCompatibleDriver();
        $this->preflightReferences();
        $this->removeRequestedColumnComments();
        $this->alignReferenceColumnTypes();
        $this->detachKnownOrphanReferences();
        $this->addForeignKeys();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Migration 040 is forward-only; removing data-integrity constraints requires a reviewed decision.'
        );
    }

    private function assertMySqlCompatibleDriver(): void
    {
        if (!in_array($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql'], true)) {
            throw new RuntimeException('Migration 040 requires MySQL or MariaDB.');
        }
    }

    private function preflightReferences(): void
    {
        foreach (self::REFERENCES as [$table, $column, $parent, $action]) {
            $source = $this->columnMetadata($table, $column);
            $target = $this->columnMetadata($parent, 'id');
            if ($source === null || $target === null) {
                throw new RuntimeException("Missing expected column {$table}.{$column} or {$parent}.id.");
            }

            $existing = $this->foreignKeyTarget($table, $column);
            if ($existing !== null && $existing !== $parent) {
                throw new RuntimeException(
                    "Unexpected existing foreign key on {$table}.{$column}: references {$existing}."
                );
            }

            $this->assertCompatibleTypes($table, $column, $source, $parent, $target);
            $this->assertOrphansAreExpected($table, $column, $parent);
            $this->assertDeleteActionIsSafe($table, $column, $source, $action);
        }
    }

    private function assertCompatibleTypes(
        string $table,
        string $column,
        array $source,
        string $parent,
        array $target
    ): void {
        $sourceType = strtolower((string) $source['COLUMN_TYPE']);
        $targetType = strtolower((string) $target['COLUMN_TYPE']);
        if ($sourceType === $targetType) {
            return;
        }

        $isApprovedUnsignedInteger = in_array([$table, $column], self::TYPE_ALIGNED_COLUMNS, true)
            && preg_match('/^int(?:\(\d+\))? unsigned$/', $sourceType) === 1
            && preg_match('/^int(?:\(\d+\))?$/', $targetType) === 1;
        $isApprovedUnsignedBigint = in_array([$table, $column], self::BIGINT_UNSIGNED_COLUMNS, true)
            && preg_match('/^int(?:\(\d+\))? unsigned$/', $sourceType) === 1
            && preg_match('/^bigint(?:\(\d+\))? unsigned$/', $targetType) === 1;
        if (!$isApprovedUnsignedInteger && !$isApprovedUnsignedBigint) {
            throw new RuntimeException(
                "Incompatible reference types for {$table}.{$column} ({$sourceType}) and {$parent}.id ({$targetType})."
            );
        }
    }

    private function assertOrphansAreExpected(string $table, string $column, string $parent): void
    {
        $sql = sprintf(
            'SELECT child.id, child.%s AS reference_id
             FROM %s child
             LEFT JOIN %s parent ON parent.id = child.%s
             WHERE child.%s IS NOT NULL AND parent.id IS NULL',
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($parent),
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($column)
        );
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $allowed = self::ALLOWED_ORPHANS["{$table}.{$column}"] ?? [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $referenceId = (int) $row['reference_id'];
            if (($allowed[$id] ?? null) !== $referenceId) {
                throw new RuntimeException(
                    "Unexpected orphan reference in {$table}.{$column}: row {$id} references missing {$parent} {$referenceId}."
                );
            }
        }

        foreach ($allowed as $id => $referenceId) {
            $matches = array_filter(
                $rows,
                static fn (array $row): bool => (int) $row['id'] === $id
                    && (int) $row['reference_id'] === $referenceId
            );
            if ($matches !== [] || $rows === []) {
                continue;
            }

            $rowExists = $this->pdo->prepare(
                sprintf('SELECT %s FROM %s WHERE id = ?', $this->quoteIdentifier($column), $this->quoteIdentifier($table))
            );
            $rowExists->execute([$id]);
            $currentReference = $rowExists->fetchColumn();
            if ($currentReference !== false && $currentReference !== null && (int) $currentReference !== $referenceId) {
                throw new RuntimeException(
                    "Known orphan row {$table}.id={$id} changed unexpectedly; refusing to alter its reference."
                );
            }
        }
    }

    private function assertDeleteActionIsSafe(string $table, string $column, array $source, string $action): void
    {
        if ($action === 'SET NULL' && $source['IS_NULLABLE'] !== 'YES') {
            throw new RuntimeException("ON DELETE SET NULL requires nullable {$table}.{$column}.");
        }
    }

    private function removeRequestedColumnComments(): void
    {
        $placeholders = implode(',', array_fill(0, count(self::COMMENT_TABLES), '?'));
        $statement = $this->pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA,
                    CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ({$placeholders})
               AND COLUMN_COMMENT <> ''"
        );
        $statement->execute(self::COMMENT_TABLES);

        $alterations = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $definition = $this->columnDefinitionWithoutComment($column);
            $alterations[$column['TABLE_NAME']][] = 'MODIFY COLUMN '
                . $this->quoteIdentifier($column['COLUMN_NAME']) . ' ' . $definition;
        }

        foreach ($alterations as $table => $definitions) {
            $this->pdo->exec(
                'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ' . implode(', ', $definitions)
            );
        }
    }

    private function columnDefinitionWithoutComment(array $column): string
    {
        $definition = $column['COLUMN_TYPE'];
        if ($column['CHARACTER_SET_NAME'] !== null) {
            $definition .= ' CHARACTER SET ' . $this->quoteIdentifier($column['CHARACTER_SET_NAME']);
        }
        if ($column['COLLATION_NAME'] !== null) {
            $definition .= ' COLLATE ' . $this->quoteIdentifier($column['COLLATION_NAME']);
        }

        $definition .= $column['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
        if (!$this->isNullDefault($column['COLUMN_DEFAULT']) || $column['IS_NULLABLE'] === 'YES') {
            $definition .= ' DEFAULT ' . $this->formatDefault($column);
        }
        if (!empty($column['GENERATION_EXPRESSION'])) {
            $definition .= ' GENERATED ALWAYS AS (' . $column['GENERATION_EXPRESSION'] . ')';
        }
        if ($column['EXTRA'] !== '') {
            $extra = preg_replace('/\bDEFAULT_GENERATED\b/i', '', $column['EXTRA']);
            $definition .= ' ' . trim($extra);
        }

        return $definition . " COMMENT ''";
    }

    private function formatDefault(array $column): string
    {
        if ($this->isNullDefault($column['COLUMN_DEFAULT'])) {
            return 'NULL';
        }

        $default = (string) $column['COLUMN_DEFAULT'];
        if (strlen($default) >= 2 && $default[0] === "'" && substr($default, -1) === "'") {
            $default = str_replace("''", "'", substr($default, 1, -1));
        }
        if (preg_match('/^(CURRENT_TIMESTAMP(?:\(\d+\))?|CURRENT_DATE|CURRENT_TIME(?:\(\d+\))?)$/i', $default) === 1) {
            return $default;
        }
        if (preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|bit|year)/i', $column['COLUMN_TYPE']) === 1) {
            return $default;
        }

        return $this->pdo->quote($default);
    }

    private function alignReferenceColumnTypes(): void
    {
        foreach (self::TYPE_ALIGNED_COLUMNS as [$table, $column]) {
            $metadata = $this->columnMetadata($table, $column);
            if ($metadata === null) {
                throw new RuntimeException("Missing type-alignment column {$table}.{$column}.");
            }

            $type = strtolower((string) $metadata['COLUMN_TYPE']);
            if (preg_match('/^int(?:\(\d+\))?$/', $type) === 1) {
                continue;
            }
            if (preg_match('/^int(?:\(\d+\))? unsigned$/', $type) !== 1) {
                throw new RuntimeException("Unexpected type {$type} for {$table}.{$column}.");
            }

            $default = $this->isNullDefault($metadata['COLUMN_DEFAULT'])
                ? ''
                : ' DEFAULT ' . (int) $metadata['COLUMN_DEFAULT'];
            $nullability = $metadata['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
            $this->pdo->exec(sprintf(
                'ALTER TABLE %s MODIFY COLUMN %s INT%s%s COMMENT %s',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($column),
                $nullability,
                $default,
                $this->pdo->quote((string) $metadata['COLUMN_COMMENT'])
            ));
        }

        foreach (self::BIGINT_UNSIGNED_COLUMNS as [$table, $column]) {
            $metadata = $this->columnMetadata($table, $column);
            if ($metadata === null) {
                throw new RuntimeException("Missing type-alignment column {$table}.{$column}.");
            }

            $type = strtolower((string) $metadata['COLUMN_TYPE']);
            if (preg_match('/^bigint(?:\(\d+\))? unsigned$/', $type) === 1) {
                continue;
            }
            if (preg_match('/^int(?:\(\d+\))? unsigned$/', $type) !== 1) {
                throw new RuntimeException("Unexpected type {$type} for {$table}.{$column}.");
            }

            $default = $this->isNullDefault($metadata['COLUMN_DEFAULT'])
                ? ''
                : ' DEFAULT ' . (int) $metadata['COLUMN_DEFAULT'];
            $nullability = $metadata['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
            $this->pdo->exec(sprintf(
                'ALTER TABLE %s MODIFY COLUMN %s BIGINT UNSIGNED%s%s COMMENT %s',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($column),
                $nullability,
                $default,
                $this->pdo->quote((string) $metadata['COLUMN_COMMENT'])
            ));
        }
    }

    private function detachKnownOrphanReferences(): void
    {
        foreach (self::ALLOWED_ORPHANS as $reference => $orphans) {
            [$table, $column] = explode('.', $reference, 2);
            $parent = $this->parentTableFor($table, $column);
            foreach ($orphans as $id => $referenceId) {
                $statement = $this->pdo->prepare(sprintf(
                    'UPDATE %s child SET child.%s = NULL
                     WHERE child.id = ? AND child.%s = ?
                       AND NOT EXISTS (
                           SELECT 1 FROM %s parent WHERE parent.id = child.%s
                       )',
                    $this->quoteIdentifier($table),
                    $this->quoteIdentifier($column),
                    $this->quoteIdentifier($column),
                    $this->quoteIdentifier($parent),
                    $this->quoteIdentifier($column)
                ));
                $statement->execute([$id, $referenceId]);

                if ($statement->rowCount() > 1) {
                    throw new RuntimeException("Unexpected number of updated rows in {$table}.{$column}.");
                }
            }
        }

    }

    private function parentTableFor(string $table, string $column): string
    {
        foreach (self::REFERENCES as [$sourceTable, $sourceColumn, $parent]) {
            if ($sourceTable === $table && $sourceColumn === $column) {
                return $parent;
            }
        }

        throw new RuntimeException("No parent table is configured for {$table}.{$column}.");
    }

    private function addForeignKeys(): void
    {
        foreach (self::REFERENCES as [$table, $column, $parent, $action]) {
            $existing = $this->foreignKeyTarget($table, $column);
            if ($existing === $parent) {
                continue;
            }

            $name = $this->foreignKeyName($table, $column);
            $this->pdo->exec(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE %s',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($name),
                $this->quoteIdentifier($column),
                $this->quoteIdentifier($parent),
                $action
            ));
        }
    }

    private function columnMetadata(string $table, string $column): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function foreignKeyTarget(string $table, string $column): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1'
        );
        $statement->execute([$table, $column]);
        $target = $statement->fetchColumn();

        return $target === false ? null : (string) $target;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function isNullDefault(mixed $value): bool
    {
        return $value === null || strtoupper((string) $value) === 'NULL';
    }

    private function foreignKeyName(string $table, string $column): string
    {
        $name = 'fk040_' . $table . '_' . $column;
        if (strlen($name) <= 64) {
            return $name;
        }

        return substr($name, 0, 47) . '_' . substr(sha1($name), 0, 12);
    }
}
