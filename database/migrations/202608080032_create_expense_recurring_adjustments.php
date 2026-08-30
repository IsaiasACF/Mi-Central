<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS expense_recurring_adjustments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            recurring_rule_id BIGINT UNSIGNED NOT NULL,
            period_month DATE NOT NULL,
            action VARCHAR(24) NOT NULL DEFAULT 'generate',
            description VARCHAR(180) NULL,
            amount_override TINYINT(1) NOT NULL DEFAULT 0,
            amount_clp INT UNSIGNED NULL,
            due_on DATE NULL,
            category_id BIGINT UNSIGNED NULL,
            payment_method_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY expense_recurring_adjustments_rule_period_unique (user_id, recurring_rule_id, period_month),
            INDEX expense_recurring_adjustments_rule_index (recurring_rule_id),
            INDEX expense_recurring_adjustments_user_period_index (user_id, period_month),
            INDEX expense_recurring_adjustments_category_index (category_id),
            INDEX expense_recurring_adjustments_payment_method_index (payment_method_id),
            CONSTRAINT expense_recurring_adjustments_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT expense_recurring_adjustments_rule_fk
                FOREIGN KEY (recurring_rule_id) REFERENCES expense_recurring_rules (id)
                ON DELETE CASCADE,
            CONSTRAINT expense_recurring_adjustments_category_fk
                FOREIGN KEY (category_id) REFERENCES expense_categories (id)
                ON DELETE SET NULL,
            CONSTRAINT expense_recurring_adjustments_payment_method_fk
                FOREIGN KEY (payment_method_id) REFERENCES expense_payment_methods (id)
                ON DELETE SET NULL,
            CONSTRAINT expense_recurring_adjustments_action_check
                CHECK (action IN ('generate', 'skip')),
            CONSTRAINT expense_recurring_adjustments_period_check
                CHECK (DAYOFMONTH(period_month) = 1),
            CONSTRAINT expense_recurring_adjustments_amount_override_check
                CHECK (amount_override IN (0, 1)),
            CONSTRAINT expense_recurring_adjustments_amount_check
                CHECK (amount_clp IS NULL OR amount_clp >= 0),
            CONSTRAINT expense_recurring_adjustments_active_check
                CHECK (active IN (0, 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
