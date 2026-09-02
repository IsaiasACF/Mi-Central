<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    if (!expense_notification_index_exists($pdo, 'expenses', 'expenses_user_status_due_index')) {
        $pdo->exec('ALTER TABLE expenses ADD INDEX expenses_user_status_due_index (user_id, status, due_on)');
    }
};

function expense_notification_index_exists(\PDO $pdo, string $table, string $index): bool
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
