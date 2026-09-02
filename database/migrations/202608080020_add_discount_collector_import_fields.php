<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    if (!discount_collector_import_column_exists($pdo, 'collector_key')) {
        $pdo->exec(
            'ALTER TABLE discount_promotions
             ADD COLUMN collector_key VARCHAR(120) NULL AFTER source_type'
        );
    }

    if (!discount_collector_import_column_exists($pdo, 'dedupe_fingerprint')) {
        $pdo->exec(
            'ALTER TABLE discount_promotions
             ADD COLUMN dedupe_fingerprint CHAR(64) NULL AFTER last_seen_at'
        );
    }

    if (!discount_collector_import_index_exists($pdo, 'discount_promotions_collector_identity_unique')) {
        $pdo->exec(
            'ALTER TABLE discount_promotions
             ADD UNIQUE KEY discount_promotions_collector_identity_unique (source_type, collector_key, source_key)'
        );
    }

    if (!discount_collector_import_index_exists($pdo, 'discount_promotions_collector_fingerprint_index')) {
        $pdo->exec(
            'ALTER TABLE discount_promotions
             ADD INDEX discount_promotions_collector_fingerprint_index (source_type, collector_key, dedupe_fingerprint)'
        );
    }

    if (!discount_collector_import_index_exists($pdo, 'discount_promotions_fingerprint_index')) {
        $pdo->exec(
            'ALTER TABLE discount_promotions
             ADD INDEX discount_promotions_fingerprint_index (dedupe_fingerprint)'
        );
    }
};

function discount_collector_import_column_exists(\PDO $pdo, string $column): bool
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

function discount_collector_import_index_exists(\PDO $pdo, string $index): bool
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
