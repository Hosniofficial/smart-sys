<?php

declare(strict_types=1);

/** Track credential versions so password changes invalidate access JWTs immediately. */
final class RevokeSessionsAfterPasswordChange
{
    public function __construct(private PDO $pdo)
    {
    }

    public function up(): void
    {
        if ($this->columnExists()) {
            return;
        }

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec(
                'ALTER TABLE users ADD COLUMN token_version INTEGER NOT NULL DEFAULT 0'
            );
            return;
        }

        $this->pdo->exec(
            'ALTER TABLE users ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0'
        );
    }

    public function down(): void
    {
        if (!$this->columnExists()) {
            return;
        }

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('ALTER TABLE users DROP COLUMN token_version');
            return;
        }

        $this->pdo->exec('ALTER TABLE users DROP COLUMN token_version');
    }

    private function columnExists(): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($this->pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if ($column['name'] === 'token_version') {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'token_version'"
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }
}
