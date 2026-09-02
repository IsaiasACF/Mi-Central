<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $pdo->exec(
        "UPDATE users
         SET approval_status = 'approved',
             is_active = 1,
             approved_at = COALESCE(approved_at, NOW()),
             rejected_at = NULL
         WHERE approval_status = 'pending'"
    );

    $pdo->exec(
        "UPDATE users
         SET approval_status = 'approved'
         WHERE approval_status = 'rejected'"
    );
};
