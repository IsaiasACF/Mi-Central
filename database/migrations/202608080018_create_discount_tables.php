<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS discount_benefit_programs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider_name VARCHAR(180) NOT NULL,
            normalized_provider_name VARCHAR(180) NOT NULL,
            name VARCHAR(180) NOT NULL,
            normalized_name VARCHAR(180) NOT NULL,
            benefit_type VARCHAR(32) NOT NULL,
            product_name VARCHAR(180) NULL,
            normalized_product_name VARCHAR(180) NOT NULL DEFAULT '',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY discount_benefit_programs_unique (normalized_provider_name, normalized_name, benefit_type, normalized_product_name),
            INDEX discount_benefit_programs_type_index (benefit_type),
            INDEX discount_benefit_programs_active_index (active),
            CONSTRAINT discount_benefit_programs_type_check
                CHECK (benefit_type IN ('bank_card', 'bank_account', 'mobile', 'wallet', 'membership', 'other')),
            CONSTRAINT discount_benefit_programs_active_check
                CHECK (active IN (0, 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_discount_benefits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            benefit_program_id BIGINT UNSIGNED NOT NULL,
            nickname VARCHAR(180) NULL,
            notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY user_discount_benefits_user_program_unique (user_id, benefit_program_id),
            INDEX user_discount_benefits_user_index (user_id),
            INDEX user_discount_benefits_program_index (benefit_program_id),
            CONSTRAINT user_discount_benefits_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT user_discount_benefits_program_fk
                FOREIGN KEY (benefit_program_id) REFERENCES discount_benefit_programs (id)
                ON DELETE RESTRICT,
            CONSTRAINT user_discount_benefits_active_check
                CHECK (active IN (0, 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS discount_merchants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            normalized_name VARCHAR(180) NOT NULL,
            category VARCHAR(120) NULL,
            website_url VARCHAR(500) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY discount_merchants_name_unique (normalized_name),
            INDEX discount_merchants_category_index (category),
            INDEX discount_merchants_active_index (active),
            CONSTRAINT discount_merchants_active_check
                CHECK (active IN (0, 1))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS discount_promotions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            merchant_id BIGINT UNSIGNED NULL,
            title VARCHAR(220) NOT NULL,
            description TEXT NULL,
            discount_type VARCHAR(32) NOT NULL,
            discount_value DECIMAL(12,2) NULL,
            max_discount_clp INT UNSIGNED NULL,
            promo_code VARCHAR(120) NULL,
            channel VARCHAR(24) NOT NULL DEFAULT 'both',
            starts_on DATE NULL,
            ends_on DATE NULL,
            terms TEXT NULL,
            source_type VARCHAR(24) NOT NULL DEFAULT 'manual',
            source_key VARCHAR(255) NULL,
            source_url VARCHAR(500) NULL,
            last_seen_at DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX discount_promotions_merchant_index (merchant_id),
            INDEX discount_promotions_active_dates_index (is_active, starts_on, ends_on),
            INDEX discount_promotions_source_index (source_type, source_key),
            CONSTRAINT discount_promotions_merchant_fk
                FOREIGN KEY (merchant_id) REFERENCES discount_merchants (id)
                ON DELETE SET NULL,
            CONSTRAINT discount_promotions_discount_type_check
                CHECK (discount_type IN ('percentage', 'fixed_amount', 'special_price', 'other')),
            CONSTRAINT discount_promotions_channel_check
                CHECK (channel IN ('in_store', 'online', 'both')),
            CONSTRAINT discount_promotions_source_type_check
                CHECK (source_type IN ('manual', 'collector')),
            CONSTRAINT discount_promotions_active_check
                CHECK (is_active IN (0, 1)),
            CONSTRAINT discount_promotions_dates_check
                CHECK (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS discount_promotion_benefits (
            promotion_id BIGINT UNSIGNED NOT NULL,
            benefit_program_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (promotion_id, benefit_program_id),
            INDEX discount_promotion_benefits_program_index (benefit_program_id),
            CONSTRAINT discount_promotion_benefits_promotion_fk
                FOREIGN KEY (promotion_id) REFERENCES discount_promotions (id)
                ON DELETE CASCADE,
            CONSTRAINT discount_promotion_benefits_program_fk
                FOREIGN KEY (benefit_program_id) REFERENCES discount_benefit_programs (id)
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS discount_promotion_days (
            promotion_id BIGINT UNSIGNED NOT NULL,
            weekday TINYINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (promotion_id, weekday),
            INDEX discount_promotion_days_weekday_index (weekday),
            CONSTRAINT discount_promotion_days_promotion_fk
                FOREIGN KEY (promotion_id) REFERENCES discount_promotions (id)
                ON DELETE CASCADE,
            CONSTRAINT discount_promotion_days_weekday_check
                CHECK (weekday BETWEEN 1 AND 7)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_discount_favorites (
            user_id BIGINT UNSIGNED NOT NULL,
            promotion_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, promotion_id),
            INDEX user_discount_favorites_promotion_index (promotion_id),
            CONSTRAINT user_discount_favorites_user_fk
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE,
            CONSTRAINT user_discount_favorites_promotion_fk
                FOREIGN KEY (promotion_id) REFERENCES discount_promotions (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
