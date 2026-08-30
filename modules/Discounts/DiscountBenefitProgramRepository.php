<?php
declare(strict_types=1);

namespace Modules\Discounts;

use PDO;

final class DiscountBenefitProgramRepository
{
    private const COLUMNS = 'id, provider_name, normalized_provider_name, name, normalized_name, benefit_type, product_name, normalized_product_name, active, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
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
                :product_name,
                :normalized_product_name,
                :active
            )'
        );
        $statement->execute([
            'provider_name' => $data['provider_name'],
            'normalized_provider_name' => $data['normalized_provider_name'],
            'name' => $data['name'],
            'normalized_name' => $data['normalized_name'],
            'benefit_type' => $data['benefit_type'],
            'product_name' => $data['product_name'],
            'normalized_product_name' => $data['normalized_product_name'],
            'active' => $data['active'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $programId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM discount_benefit_programs
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $programId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNormalized(string $provider, string $name, string $type, string $product): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM discount_benefit_programs
             WHERE normalized_provider_name = :provider
               AND normalized_name = :name
               AND benefit_type = :type
               AND normalized_product_name = :product
             LIMIT 1'
        );
        $statement->execute([
            'provider' => $provider,
            'name' => $name,
            'type' => $type,
            'product' => $product,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function normalizedExists(string $provider, string $name, string $type, string $product, ?int $exceptId = null): bool
    {
        $where = 'normalized_provider_name = :provider AND normalized_name = :name AND benefit_type = :type AND normalized_product_name = :product';
        $params = [
            'provider' => $provider,
            'name' => $name,
            'type' => $type,
            'product' => $product,
        ];

        if ($exceptId !== null) {
            $where .= ' AND id <> :except_id';
            $params['except_id'] = $exceptId;
        }

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM discount_benefit_programs WHERE ' . $where . ' LIMIT 1'
        );
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (($filters['active'] ?? null) === true) {
            $where[] = 'active = 1';
        }

        if (is_string($filters['benefit_type'] ?? null) && $filters['benefit_type'] !== '') {
            $where[] = 'benefit_type = :benefit_type';
            $params['benefit_type'] = $filters['benefit_type'];
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM discount_benefit_programs'
             . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . '
             ORDER BY active DESC, provider_name ASC, name ASC, id ASC'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function delete(int $programId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM discount_benefit_programs WHERE id = :id');
        $statement->execute(['id' => $programId]);

        return $statement->rowCount() > 0;
    }
}
