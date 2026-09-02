<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS expense_recurring_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            frequency VARCHAR(24) NOT NULL,
            interval_value INT UNSIGNED NOT NULL DEFAULT 1,
            day_of_month TINYINT UNSIGNED NULL,
            default_amount_clp INT UNSIGNED NULL,
            default_category_id BIGINT UNSIGNED NULL,
            default_payment_method_id BIGINT UNSIGNED NULL,
            starts_on DATE NOT NULL,
            ends_on DATE NULL,
            next_generation_on DATE NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY expense_recurring_rules_user_service_unique (user_id, service_id),
            INDEX expense_recurring_rules_due_index (active, next_generation_on),
            INDEX expense_recurring_rules_service_index (service_id),
            INDEX expense_recurring_rules_category_index (default_category_id),
            INDEX expense_recurring_rules_payment_method_index (default_payment_method_id),
            CONSTRAINT expense_recurring_rules_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT expense_recurring_rules_service_fk
                FOREIGN KEY (service_id) REFERENCES expense_services (id)
                ON DELETE CASCADE,
            CONSTRAINT expense_recurring_rules_category_fk
                FOREIGN KEY (default_category_id) REFERENCES expense_categories (id)
                ON DELETE SET NULL,
            CONSTRAINT expense_recurring_rules_payment_method_fk
                FOREIGN KEY (default_payment_method_id) REFERENCES expense_payment_methods (id)
                ON DELETE SET NULL,
            CONSTRAINT expense_recurring_rules_frequency_check
                CHECK (frequency IN ('monthly')),
            CONSTRAINT expense_recurring_rules_interval_check
                CHECK (interval_value >= 1),
            CONSTRAINT expense_recurring_rules_day_check
                CHECK (day_of_month IS NULL OR (day_of_month >= 1 AND day_of_month <= 31)),
            CONSTRAINT expense_recurring_rules_amount_check
                CHECK (default_amount_clp IS NULL OR default_amount_clp >= 0),
            CONSTRAINT expense_recurring_rules_active_check
                CHECK (active IN (0, 1)),
            CONSTRAINT expense_recurring_rules_dates_check
                CHECK (ends_on IS NULL OR ends_on >= starts_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!expense_recurring_column_exists($pdo, 'expenses', 'recurring_rule_id')) {
        $pdo->exec(
            'ALTER TABLE expenses
             ADD COLUMN recurring_rule_id BIGINT UNSIGNED NULL AFTER service_id'
        );
    }

    if (!expense_recurring_column_nullable($pdo, 'expenses', 'amount_clp')) {
        $pdo->exec('ALTER TABLE expenses MODIFY amount_clp INT UNSIGNED NULL');
    }

    if (!expense_recurring_index_exists($pdo, 'expenses', 'expenses_recurring_rule_index')) {
        $pdo->exec('ALTER TABLE expenses ADD INDEX expenses_recurring_rule_index (recurring_rule_id)');
    }

    if (!expense_recurring_index_exists($pdo, 'expenses', 'expenses_recurring_rule_period_unique')) {
        $pdo->exec('ALTER TABLE expenses ADD UNIQUE KEY expenses_recurring_rule_period_unique (recurring_rule_id, period_month)');
    }

    if (!expense_recurring_foreign_key_exists($pdo, 'expenses', 'expenses_recurring_rule_fk')) {
        $pdo->exec(
            'ALTER TABLE expenses
             ADD CONSTRAINT expenses_recurring_rule_fk
                FOREIGN KEY (recurring_rule_id) REFERENCES expense_recurring_rules (id)
                ON DELETE SET NULL'
        );
    }
};

function expense_recurring_column_exists(\PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column'
    );
    $statement->execute([
        'table' => $table,
        'column' => $column,
    ]);

    return (int) $statement->fetchColumn() === 1;
}

function expense_recurring_column_nullable(\PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT IS_NULLABLE
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column
         LIMIT 1'
    );
    $statement->execute([
        'table' => $table,
        'column' => $column,
    ]);

    return $statement->fetchColumn() === 'YES';
}

function expense_recurring_index_exists(\PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index'
    );
    $statement->execute([
        'table' => $table,
        'index' => $index,
    ]);

    return (int) $statement->fetchColumn() >= 1;
}

function expense_recurring_foreign_key_exists(\PDO $pdo, string $table, string $constraint): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.table_constraints
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND constraint_name = :constraint
           AND constraint_type = \'FOREIGN KEY\''
    );
    $statement->execute([
        'table' => $table,
        'constraint' => $constraint,
    ]);

    return (int) $statement->fetchColumn() >= 1;
}
