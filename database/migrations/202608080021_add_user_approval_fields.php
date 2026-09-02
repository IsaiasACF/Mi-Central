<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "ALTER TABLE users
            ADD COLUMN approval_status VARCHAR(16) NOT NULL DEFAULT 'approved' AFTER password_hash,
            ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
            ADD COLUMN approved_at DATETIME NULL AFTER last_login_at,
            ADD COLUMN approved_by_user_id BIGINT UNSIGNED NULL AFTER approved_at,
            ADD COLUMN rejected_at DATETIME NULL AFTER approved_by_user_id,
            ADD INDEX users_approval_status_index (approval_status, created_at),
            ADD INDEX users_admin_index (is_admin),
            ADD INDEX users_approved_by_index (approved_by_user_id),
            ADD CONSTRAINT users_approval_status_check
                CHECK (approval_status IN ('pending', 'approved', 'rejected')),
            ADD CONSTRAINT users_is_admin_check
                CHECK (is_admin IN (0, 1)),
            ADD CONSTRAINT users_approved_by_fk
                FOREIGN KEY (approved_by_user_id) REFERENCES users (id)
                ON DELETE SET NULL"
    );

    $pdo->exec(
        "UPDATE users
         SET approval_status = 'approved',
             approved_at = COALESCE(approved_at, created_at)
         WHERE approval_status = 'approved' AND is_active = 1"
    );
};
