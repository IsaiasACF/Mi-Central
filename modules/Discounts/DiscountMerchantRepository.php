<?php
declare(strict_types=1);

namespace Modules\Discounts;

use PDO;

final class DiscountMerchantRepository
{
    private const COLUMNS = 'id, name, normalized_name, category, website_url, active, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO discount_merchants (name, normalized_name, category, website_url, active)
             VALUES (:name, :normalized_name, :category, :website_url, :active)'
        );
        $statement->execute([
            'name' => $data['name'],
            'normalized_name' => $data['normalized_name'],
            'category' => $data['category'],
            'website_url' => $data['website_url'],
            'active' => $data['active'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $merchantId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM discount_merchants
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $merchantId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNormalizedName(string $normalizedName): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM discount_merchants
             WHERE normalized_name = :normalized_name
             LIMIT 1'
        );
        $statement->execute(['normalized_name' => $normalizedName]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function normalizedNameExists(string $normalizedName, ?int $exceptId = null): bool
    {
        $where = 'normalized_name = :normalized_name';
        $params = ['normalized_name' => $normalizedName];

        if ($exceptId !== null) {
            $where .= ' AND id <> :except_id';
            $params['except_id'] = $exceptId;
        }

        $statement = $this->pdo->prepare('SELECT 1 FROM discount_merchants WHERE ' . $where . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . '
             FROM discount_merchants
             WHERE active = 1
             ORDER BY name ASC, id ASC'
        );

        return $statement === false ? [] : $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = [];

        if (($filters['active'] ?? null) === true) {
            $where[] = 'active = 1';
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM discount_merchants'
             . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . '
             ORDER BY active DESC, name ASC, id ASC'
        );
        $statement->execute();

        return $statement->fetchAll();
    }
}
