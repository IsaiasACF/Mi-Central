<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS expense_categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            normalized_name VARCHAR(120) NOT NULL,
            color CHAR(7) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY expense_categories_user_name_unique (user_id, normalized_name),
            INDEX expense_categories_user_index (user_id),
            INDEX expense_categories_active_index (active),
            CONSTRAINT expense_categories_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT expense_categories_color_check
                CHECK (color IS NULL OR color REGEXP '^#[0-9A-F]{6}$'),
            CONSTRAINT expense_categories_active_check
                CHECK (active IN (0, 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS expense_payment_methods (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            normalized_name VARCHAR(120) NOT NULL,
            type VARCHAR(32) NOT NULL,
            institution_name VARCHAR(120) NULL,
            notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY expense_payment_methods_user_name_unique (user_id, normalized_name),
            INDEX expense_payment_methods_user_index (user_id),
            INDEX expense_payment_methods_type_index (type),
            INDEX expense_payment_methods_active_index (active),
            CONSTRAINT expense_payment_methods_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT expense_payment_methods_type_check
                CHECK (type IN ('card', 'bank_account', 'automatic_payment', 'wallet', 'webpay', 'transfer', 'cash', 'other')),
            CONSTRAINT expense_payment_methods_active_check
                CHECK (active IN (0, 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS expense_services (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            name VARCHAR(160) NOT NULL,
            normalized_name VARCHAR(160) NOT NULL,
            default_amount_clp INT UNSIGNED NULL,
            notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY expense_services_user_name_unique (user_id, normalized_name),
            INDEX expense_services_user_index (user_id),
            INDEX expense_services_category_index (category_id),
            INDEX expense_services_active_index (active),
            CONSTRAINT expense_services_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT expense_services_category_fk
                FOREIGN KEY (category_id) REFERENCES expense_categories (id)
                ON DELETE SET NULL,
            CONSTRAINT expense_services_amount_check
                CHECK (default_amount_clp IS NULL OR default_amount_clp >= 0),
            CONSTRAINT expense_services_active_check
                CHECK (active IN (0, 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS expense_service_payment_methods (
            service_id BIGINT UNSIGNED NOT NULL,
            payment_method_id BIGINT UNSIGNED NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (service_id, payment_method_id),
            INDEX expense_service_payment_methods_method_index (payment_method_id),
            CONSTRAINT expense_service_payment_methods_service_fk
                FOREIGN KEY (service_id) REFERENCES expense_services (id)
                ON DELETE CASCADE,
            CONSTRAINT expense_service_payment_methods_method_fk
                FOREIGN KEY (payment_method_id) REFERENCES expense_payment_methods (id)
                ON DELETE CASCADE,
            CONSTRAINT expense_service_payment_methods_default_check
                CHECK (is_default IN (0, 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS expenses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NULL,
            category_id BIGINT UNSIGNED NULL,
            period_month DATE NOT NULL,
            description VARCHAR(180) NOT NULL,
            amount_clp INT UNSIGNED NOT NULL,
            installment_current INT UNSIGNED NULL,
            installment_total INT UNSIGNED NULL,
            due_on DATE NULL,
            paid_on DATE NULL,
            payment_method_id BIGINT UNSIGNED NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX expenses_user_period_index (user_id, period_month),
            INDEX expenses_user_due_on_index (user_id, due_on),
            INDEX expenses_user_status_index (user_id, status),
            INDEX expenses_service_index (service_id),
            INDEX expenses_category_index (category_id),
            INDEX expenses_payment_method_index (payment_method_id),
            CONSTRAINT expenses_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT expenses_service_fk
                FOREIGN KEY (service_id) REFERENCES expense_services (id)
                ON DELETE SET NULL,
            CONSTRAINT expenses_category_fk
                FOREIGN KEY (category_id) REFERENCES expense_categories (id)
                ON DELETE SET NULL,
            CONSTRAINT expenses_payment_method_fk
                FOREIGN KEY (payment_method_id) REFERENCES expense_payment_methods (id)
                ON DELETE SET NULL,
            CONSTRAINT expenses_status_check
                CHECK (status IN ('pending', 'paid', 'cancelled')),
            CONSTRAINT expenses_period_month_check
                CHECK (DAYOFMONTH(period_month) = 1),
            CONSTRAINT expenses_amount_check
                CHECK (amount_clp >= 0),
            CONSTRAINT expenses_installments_check
                CHECK (
                    (installment_current IS NULL AND installment_total IS NULL)
                    OR (
                        installment_current IS NOT NULL
                        AND installment_total IS NOT NULL
                        AND installment_current >= 1
                        AND installment_total >= 1
                        AND installment_current <= installment_total
                    )
                )
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
