<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    if (!discount_test_benefits_table_exists($pdo, 'discount_benefit_programs')) {
        return;
    }

    if (discount_test_benefits_table_exists($pdo, 'discount_promotions')) {
        $pdo->exec(
            "DELETE FROM discount_promotions
             WHERE source_type = 'collector'
               AND collector_key = 'banco_chile'
               AND source_key LIKE 'banco-test-%'"
        );
    }

    $testProgramIds = discount_test_benefits_program_ids($pdo);

    if ($testProgramIds === []) {
        return;
    }

    $canonicalId = discount_test_benefits_canonical_visa_banco_chile($pdo);
    $placeholders = implode(',', array_fill(0, count($testProgramIds), '?'));

    if (discount_test_benefits_table_exists($pdo, 'user_discount_benefits')) {
        $statement = $pdo->prepare(
            'INSERT IGNORE INTO user_discount_benefits (user_id, benefit_program_id, nickname, notes, active)
             SELECT user_id, ?, nickname, notes, active
             FROM user_discount_benefits
             WHERE benefit_program_id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$canonicalId], $testProgramIds));

        $statement = $pdo->prepare(
            'DELETE FROM user_discount_benefits
             WHERE benefit_program_id IN (' . $placeholders . ')'
        );
        $statement->execute($testProgramIds);
    }

    if (discount_test_benefits_table_exists($pdo, 'discount_promotion_benefits')) {
        $statement = $pdo->prepare(
            'INSERT IGNORE INTO discount_promotion_benefits (promotion_id, benefit_program_id)
             SELECT promotion_id, ?
             FROM discount_promotion_benefits
             WHERE benefit_program_id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$canonicalId], $testProgramIds));

        $statement = $pdo->prepare(
            'DELETE FROM discount_promotion_benefits
             WHERE benefit_program_id IN (' . $placeholders . ')'
        );
        $statement->execute($testProgramIds);
    }

    $statement = $pdo->prepare(
        'DELETE FROM discount_benefit_programs
         WHERE id IN (' . $placeholders . ')'
    );
    $statement->execute($testProgramIds);
};

function discount_test_benefits_table_exists(\PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table_name'
    );
    $statement->execute(['table_name' => $table]);

    return (int) $statement->fetchColumn() > 0;
}

/**
 * @return array<int, int>
 */
function discount_test_benefits_program_ids(\PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT id
         FROM discount_benefit_programs
         WHERE provider_name = 'Banco de Chile'
           AND name REGEXP '^Visa Test Card [0-9a-fA-F]{8}$'"
    );

    if ($statement === false) {
        return [];
    }

    return array_values(array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
}

function discount_test_benefits_canonical_visa_banco_chile(\PDO $pdo): int
{
    $statement = $pdo->prepare(
        "SELECT id
         FROM discount_benefit_programs
         WHERE normalized_provider_name = 'banco de chile'
           AND normalized_name = 'visa banco de chile'
           AND benefit_type = 'bank_card'
           AND normalized_product_name = ''
         LIMIT 1"
    );
    $statement->execute();
    $id = $statement->fetchColumn();

    if ($id !== false) {
        return (int) $id;
    }

    $statement = $pdo->prepare(
        'INSERT INTO discount_benefit_programs (
            provider_name,
            normalized_provider_name,
            name,
            normalized_name,
            benefit_type,
            product_name,
            normalized_product_name,
            active
        ) VALUES (
            :provider_name,
            :normalized_provider_name,
            :name,
            :normalized_name,
            :benefit_type,
            NULL,
            :normalized_product_name,
            1
        )'
    );
    $statement->execute([
        'provider_name' => 'Banco de Chile',
        'normalized_provider_name' => 'banco de chile',
        'name' => 'Visa Banco de Chile',
        'normalized_name' => 'visa banco de chile',
        'benefit_type' => 'bank_card',
        'normalized_product_name' => '',
    ]);

    return (int) $pdo->lastInsertId();
}
