<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    if (!discount_promotion_column_exists($pdo, 'created_by_user_id')) {
        $pdo->exec(
            'ALTER TABLE discount_promotions
             ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER merchant_id'
        );
    }

    if (!discount_promotion_index_exists($pdo, 'discount_promotions_owner_source_index')) {
        $pdo->exec(
            'ALTER TABLE discount_promotions
             ADD INDEX discount_promotions_owner_source_index (created_by_user_id, source_type, created_at)'
        );
    }

    if (!discount_promotion_fk_exists($pdo, 'discount_promotions_created_by_user_fk')) {
        $pdo->exec(
            'ALTER TABLE discount_promotions
             ADD CONSTRAINT discount_promotions_created_by_user_fk
             FOREIGN KEY (created_by_user_id) REFERENCES users (id)
             ON DELETE SET NULL'
        );
    }
};

function discount_promotion_column_exists(\PDO $pdo, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column'
    );
    $statement->execute([
        'table' => 'discount_promotions',
        'column' => $column,
    ]);

    return (int) $statement->fetchColumn() === 1;
}

function discount_promotion_index_exists(\PDO $pdo, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index_name'
    );
    $statement->execute([
        'table' => 'discount_promotions',
        'index_name' => $index,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function discount_promotion_fk_exists(\PDO $pdo, string $constraint): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.referential_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = :table
           AND constraint_name = :constraint_name'
    );
    $statement->execute([
        'table' => 'discount_promotions',
        'constraint_name' => $constraint,
    ]);

    return (int) $statement->fetchColumn() > 0;
}
