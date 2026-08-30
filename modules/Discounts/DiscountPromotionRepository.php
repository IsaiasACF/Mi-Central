<?php
declare(strict_types=1);

namespace Modules\Discounts;

use PDO;

final class DiscountPromotionRepository
{
    private const COLUMNS = 'p.id, p.merchant_id, p.created_by_user_id, p.category, p.title, p.description, p.discount_type, p.discount_value, p.max_discount_clp, p.promo_code, p.channel, p.starts_on, p.ends_on, p.terms, p.source_type, p.collector_key, p.source_key, p.source_url, p.last_seen_at, p.dedupe_fingerprint, p.is_active, p.created_at, p.updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO discount_promotions (
                merchant_id,
                created_by_user_id,
                category,
                title,
                description,
                discount_type,
                discount_value,
                max_discount_clp,
                promo_code,
                channel,
                starts_on,
                ends_on,
                terms,
                source_type,
                collector_key,
                source_key,
                source_url,
                last_seen_at,
                dedupe_fingerprint,
                is_active
            ) VALUES (
                :merchant_id,
                :created_by_user_id,
                :category,
                :title,
                :description,
                :discount_type,
                :discount_value,
                :max_discount_clp,
                :promo_code,
                :channel,
                :starts_on,
                :ends_on,
                :terms,
                :source_type,
                :collector_key,
                :source_key,
                :source_url,
                :last_seen_at,
                :dedupe_fingerprint,
                :is_active
            )'
        );
        $statement->execute([
            'merchant_id' => $data['merchant_id'],
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
            'category' => $data['category'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'max_discount_clp' => $data['max_discount_clp'],
            'promo_code' => $data['promo_code'],
            'channel' => $data['channel'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'terms' => $data['terms'],
            'source_type' => $data['source_type'],
            'collector_key' => $data['collector_key'] ?? null,
            'source_key' => $data['source_key'],
            'source_url' => $data['source_url'],
            'last_seen_at' => $data['last_seen_at'],
            'dedupe_fingerprint' => $data['dedupe_fingerprint'] ?? null,
            'is_active' => $data['is_active'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $promotionId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE p.id = :id
             LIMIT 1');
        $statement->execute(['id' => $promotionId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findManualForUser(int $userId, int $promotionId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE p.id = :id
               AND p.created_by_user_id = :user_id
               AND p.source_type = "manual"
             LIMIT 1');
        $statement->execute([
            'id' => $promotionId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCollectorByIdentity(string $collectorKey, string $sourceKey): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE p.source_type = "collector"
               AND p.collector_key = :collector_key
               AND p.source_key = :source_key
             LIMIT 1');
        $statement->execute([
            'collector_key' => $collectorKey,
            'source_key' => $sourceKey,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCollectorByFingerprint(string $collectorKey, string $fingerprint): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE p.source_type = "collector"
               AND p.collector_key = :collector_key
               AND p.dedupe_fingerprint = :fingerprint
             ORDER BY p.last_seen_at DESC, p.id DESC
             LIMIT 1');
        $statement->execute([
            'collector_key' => $collectorKey,
            'fingerprint' => $fingerprint,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByFingerprint(string $fingerprint): array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE p.dedupe_fingerprint = :fingerprint
             ORDER BY p.source_type ASC, p.collector_key ASC, p.id ASC');
        $statement->execute(['fingerprint' => $fingerprint]);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $promotionId, array $data): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE discount_promotions
             SET merchant_id = :merchant_id,
                 category = :category,
                 title = :title,
                 description = :description,
                 discount_type = :discount_type,
                 discount_value = :discount_value,
                 max_discount_clp = :max_discount_clp,
                 promo_code = :promo_code,
                 channel = :channel,
                 starts_on = :starts_on,
                 ends_on = :ends_on,
                 terms = :terms,
                 source_url = :source_url,
                 is_active = :is_active
             WHERE id = :id'
        );
        $statement->execute([
            'merchant_id' => $data['merchant_id'],
            'category' => $data['category'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'max_discount_clp' => $data['max_discount_clp'],
            'promo_code' => $data['promo_code'],
            'channel' => $data['channel'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'terms' => $data['terms'],
            'source_url' => $data['source_url'],
            'is_active' => $data['is_active'],
            'id' => $promotionId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateCollector(int $promotionId, array $data): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE discount_promotions
             SET merchant_id = :merchant_id,
                 category = :category,
                 title = :title,
                 description = :description,
                 discount_type = :discount_type,
                 discount_value = :discount_value,
                 max_discount_clp = :max_discount_clp,
                 promo_code = :promo_code,
                 channel = :channel,
                 starts_on = :starts_on,
                 ends_on = :ends_on,
                 terms = :terms,
                 collector_key = :collector_key,
                 source_key = :source_key,
                 source_url = :source_url,
                 last_seen_at = :last_seen_at,
                 dedupe_fingerprint = :dedupe_fingerprint
             WHERE id = :id
               AND source_type = "collector"'
        );
        $statement->execute([
            'merchant_id' => $data['merchant_id'],
            'category' => $data['category'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'max_discount_clp' => $data['max_discount_clp'],
            'promo_code' => $data['promo_code'],
            'channel' => $data['channel'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'terms' => $data['terms'],
            'collector_key' => $data['collector_key'],
            'source_key' => $data['source_key'],
            'source_url' => $data['source_url'],
            'last_seen_at' => $data['last_seen_at'],
            'dedupe_fingerprint' => $data['dedupe_fingerprint'],
            'id' => $promotionId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function addBenefit(int $promotionId, int $benefitProgramId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO discount_promotion_benefits (promotion_id, benefit_program_id)
             VALUES (:promotion_id, :benefit_program_id)'
        );
        $statement->execute([
            'promotion_id' => $promotionId,
            'benefit_program_id' => $benefitProgramId,
        ]);
    }

    public function promotionHasBenefit(int $promotionId, int $benefitProgramId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM discount_promotion_benefits
             WHERE promotion_id = :promotion_id AND benefit_program_id = :benefit_program_id
             LIMIT 1'
        );
        $statement->execute([
            'promotion_id' => $promotionId,
            'benefit_program_id' => $benefitProgramId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function addWeekday(int $promotionId, int $weekday): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO discount_promotion_days (promotion_id, weekday)
             VALUES (:promotion_id, :weekday)'
        );
        $statement->execute([
            'promotion_id' => $promotionId,
            'weekday' => $weekday,
        ]);
    }

    /**
     * @param array<int, int> $benefitProgramIds
     */
    public function replaceBenefits(int $promotionId, array $benefitProgramIds): void
    {
        $statement = $this->pdo->prepare('DELETE FROM discount_promotion_benefits WHERE promotion_id = :promotion_id');
        $statement->execute(['promotion_id' => $promotionId]);

        foreach ($benefitProgramIds as $benefitProgramId) {
            $this->addBenefit($promotionId, $benefitProgramId);
        }
    }

    /**
     * @param array<int, int> $weekdays
     */
    public function replaceWeekdays(int $promotionId, array $weekdays): void
    {
        $statement = $this->pdo->prepare('DELETE FROM discount_promotion_days WHERE promotion_id = :promotion_id');
        $statement->execute(['promotion_id' => $promotionId]);

        foreach ($weekdays as $weekday) {
            $this->addWeekday($promotionId, $weekday);
        }
    }

    public function promotionHasWeekday(int $promotionId, int $weekday): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM discount_promotion_days
             WHERE promotion_id = :promotion_id AND weekday = :weekday
             LIMIT 1'
        );
        $statement->execute([
            'promotion_id' => $promotionId,
            'weekday' => $weekday,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function addFavorite(int $userId, int $promotionId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_discount_favorites (user_id, promotion_id)
             VALUES (:user_id, :promotion_id)'
        );
        $statement->execute([
            'user_id' => $userId,
            'promotion_id' => $promotionId,
        ]);
    }

    public function favoriteExists(int $userId, int $promotionId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM user_discount_favorites
             WHERE user_id = :user_id AND promotion_id = :promotion_id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'promotion_id' => $promotionId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function removeFavorite(int $userId, int $promotionId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_discount_favorites
             WHERE user_id = :user_id AND promotion_id = :promotion_id'
        );
        $statement->execute([
            'user_id' => $userId,
            'promotion_id' => $promotionId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE p.is_active = 1
             ORDER BY p.created_at DESC, p.id DESC');
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listManualForUser(int $userId, array $filters = []): array
    {
        $where = [
            'p.created_by_user_id = :user_id',
            'p.source_type = "manual"',
        ];
        $params = ['user_id' => $userId];

        $status = is_string($filters['status'] ?? null) ? (string) $filters['status'] : 'active';

        if ($status === 'active') {
            $where[] = 'p.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'p.is_active = 0';
        }

        if (isset($filters['merchant_id']) && (int) $filters['merchant_id'] > 0) {
            $where[] = 'p.merchant_id = :merchant_id';
            $params['merchant_id'] = (int) $filters['merchant_id'];
        }

        if (isset($filters['collector_key']) && is_string($filters['collector_key']) && $filters['collector_key'] !== '') {
            $where[] = 'p.source_type = "collector" AND p.collector_key = :collector_key';
            $params['collector_key'] = $filters['collector_key'];
        }

        $this->addCategoryFilter($where, $params, $filters['category'] ?? null, 'manual_category');

        if (isset($filters['benefit_program_id']) && (int) $filters['benefit_program_id'] > 0) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM discount_promotion_benefits fpb
                WHERE fpb.promotion_id = p.id AND fpb.benefit_program_id = :benefit_program_id
            )';
            $params['benefit_program_id'] = (int) $filters['benefit_program_id'];
        }

        if (isset($filters['weekday']) && (int) $filters['weekday'] > 0) {
            $where[] = '(
                NOT EXISTS (
                    SELECT 1
                    FROM discount_promotion_days fpd_any
                    WHERE fpd_any.promotion_id = p.id
                )
                OR EXISTS (
                    SELECT 1
                    FROM discount_promotion_days fpd
                    WHERE fpd.promotion_id = p.id AND fpd.weekday = :weekday
                )
            )';
            $params['weekday'] = (int) $filters['weekday'];
        }

        $search = is_string($filters['search'] ?? null) ? trim((string) $filters['search']) : '';

        if ($search !== '') {
            $where[] = '(p.title LIKE :search OR m.name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.created_at DESC, p.id DESC');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForCompatibility(int $userId, array $filters = [], bool $activeOnly = false): array
    {
        $where = [
            '(p.created_by_user_id = :user_id OR p.created_by_user_id IS NULL)',
        ];
        $params = ['user_id' => $userId];

        if ($activeOnly) {
            $where[] = 'p.is_active = 1';
        }

        if (isset($filters['channel']) && in_array($filters['channel'], ['in_store', 'online', 'both'], true)) {
            if ($filters['channel'] === 'in_store') {
                $where[] = 'p.channel IN ("in_store", "both")';
            } elseif ($filters['channel'] === 'online') {
                $where[] = 'p.channel IN ("online", "both")';
            } else {
                $where[] = 'p.channel = "both"';
            }
        }

        if (isset($filters['merchant_id']) && (int) $filters['merchant_id'] > 0) {
            $where[] = 'p.merchant_id = :merchant_id';
            $params['merchant_id'] = (int) $filters['merchant_id'];
        }

        if (isset($filters['collector_key']) && is_string($filters['collector_key']) && $filters['collector_key'] !== '') {
            $where[] = 'p.source_type = "collector" AND p.collector_key = :collector_key';
            $params['collector_key'] = $filters['collector_key'];
        }

        if (isset($filters['benefit_program_id']) && (int) $filters['benefit_program_id'] > 0) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM discount_promotion_benefits cpb
                WHERE cpb.promotion_id = p.id AND cpb.benefit_program_id = :benefit_program_id
            )';
            $params['benefit_program_id'] = (int) $filters['benefit_program_id'];
        }

        $this->addCategoryFilter($where, $params, $filters['category'] ?? null, 'compat_category');

        $search = is_string($filters['search'] ?? null) ? trim((string) $filters['search']) : '';

        if ($search !== '') {
            $this->addSearchFilter($where, $params, $search, 'compat_search');
        }

        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.created_at DESC, p.id DESC');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findVisibleForUser(int $userId, int $promotionId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE p.id = :id
               AND (p.created_by_user_id = :user_id OR p.created_by_user_id IS NULL)
             LIMIT 1');
        $statement->execute([
            'id' => $promotionId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByMerchant(int $merchantId): array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE p.merchant_id = :merchant_id
             ORDER BY p.created_at DESC, p.id DESC');
        $statement->execute(['merchant_id' => $merchantId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByBenefit(int $benefitProgramId): array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             INNER JOIN discount_promotion_benefits pb ON pb.promotion_id = p.id
             WHERE pb.benefit_program_id = :benefit_program_id
             ORDER BY p.created_at DESC, p.id DESC');
        $statement->execute(['benefit_program_id' => $benefitProgramId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCurrentForDate(string $date, int $weekday): array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE p.is_active = 1
               AND (p.starts_on IS NULL OR p.starts_on <= :today_start)
               AND (p.ends_on IS NULL OR p.ends_on >= :today_end)
               AND (
                   NOT EXISTS (
                       SELECT 1 FROM discount_promotion_days d_any WHERE d_any.promotion_id = p.id
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM discount_promotion_days d
                       WHERE d.promotion_id = p.id AND d.weekday = :weekday
                   )
               )
             ORDER BY p.created_at DESC, p.id DESC');
        $statement->execute([
            'today_start' => $date,
            'today_end' => $date,
            'weekday' => $weekday,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFavoritesForUser(int $userId): array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             INNER JOIN user_discount_favorites f ON f.promotion_id = p.id
             WHERE f.user_id = :user_id
             ORDER BY f.created_at DESC, p.id DESC');
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listFavoritesVisibleForUser(int $userId, array $filters = []): array
    {
        $where = [
            'f.user_id = :user_id',
            '(p.created_by_user_id = :owner_user_id OR p.created_by_user_id IS NULL)',
        ];
        $params = [
            'user_id' => $userId,
            'owner_user_id' => $userId,
        ];

        if (isset($filters['merchant_id']) && (int) $filters['merchant_id'] > 0) {
            $where[] = 'p.merchant_id = :merchant_id';
            $params['merchant_id'] = (int) $filters['merchant_id'];
        }

        if (isset($filters['channel']) && in_array($filters['channel'], ['in_store', 'online', 'both'], true)) {
            if ($filters['channel'] === 'in_store') {
                $where[] = 'p.channel IN ("in_store", "both")';
            } elseif ($filters['channel'] === 'online') {
                $where[] = 'p.channel IN ("online", "both")';
            } else {
                $where[] = 'p.channel = "both"';
            }
        }

        if (isset($filters['benefit_program_id']) && (int) $filters['benefit_program_id'] > 0) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM discount_promotion_benefits fvb
                WHERE fvb.promotion_id = p.id AND fvb.benefit_program_id = :benefit_program_id
            )';
            $params['benefit_program_id'] = (int) $filters['benefit_program_id'];
        }

        $this->addCategoryFilter($where, $params, $filters['category'] ?? null, 'fav_category');

        $search = is_string($filters['search'] ?? null) ? trim((string) $filters['search']) : '';

        if ($search !== '') {
            $this->addSearchFilter($where, $params, $search, 'fav_search');
        }

        $statement = $this->pdo->prepare($this->selectSql() . '
             INNER JOIN user_discount_favorites f ON f.promotion_id = p.id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY f.created_at DESC, p.id DESC');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, int>
     */
    public function listFavoriteIdsForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT promotion_id
             FROM user_discount_favorites
             WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBenefits(int $promotionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT bp.id, bp.provider_name, bp.name, bp.benefit_type, bp.product_name, bp.active
             FROM discount_promotion_benefits pb
             INNER JOIN discount_benefit_programs bp ON bp.id = pb.benefit_program_id
             WHERE pb.promotion_id = :promotion_id
             ORDER BY bp.provider_name ASC, bp.name ASC, bp.id ASC'
        );
        $statement->execute(['promotion_id' => $promotionId]);

        return $statement->fetchAll();
    }

    /**
     * @param array<int, int> $promotionIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function listBenefitsForPromotions(array $promotionIds): array
    {
        $promotionIds = array_values(array_unique(array_filter(array_map('intval', $promotionIds), static fn (int $id): bool => $id > 0)));

        if ($promotionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($promotionIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT
                 pb.promotion_id,
                 bp.id,
                 bp.provider_name,
                 bp.name,
                 bp.benefit_type,
                 bp.product_name,
                 bp.active
             FROM discount_promotion_benefits pb
             INNER JOIN discount_benefit_programs bp ON bp.id = pb.benefit_program_id
             WHERE pb.promotion_id IN (' . $placeholders . ')
             ORDER BY pb.promotion_id ASC, bp.provider_name ASC, bp.name ASC, bp.id ASC'
        );
        $statement->execute($promotionIds);
        $grouped = [];

        foreach ($statement->fetchAll() as $row) {
            $promotionId = (int) $row['promotion_id'];
            unset($row['promotion_id']);
            $grouped[$promotionId][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<int, int> $promotionIds
     * @return array<int, array<int, int>>
     */
    public function listWeekdaysForPromotions(array $promotionIds): array
    {
        $promotionIds = array_values(array_unique(array_filter(array_map('intval', $promotionIds), static fn (int $id): bool => $id > 0)));

        if ($promotionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($promotionIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT promotion_id, weekday
             FROM discount_promotion_days
             WHERE promotion_id IN (' . $placeholders . ')
             ORDER BY promotion_id ASC, weekday ASC'
        );
        $statement->execute($promotionIds);
        $grouped = [];

        foreach ($statement->fetchAll() as $row) {
            $grouped[(int) $row['promotion_id']][] = (int) $row['weekday'];
        }

        return $grouped;
    }

    /**
     * @return array<int, int>
     */
    public function listWeekdays(int $promotionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT weekday
             FROM discount_promotion_days
             WHERE promotion_id = :promotion_id
             ORDER BY weekday ASC'
        );
        $statement->execute(['promotion_id' => $promotionId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, string>
     */
    public function listVisibleCategoriesForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT p.category
             FROM discount_promotions p
             WHERE (p.created_by_user_id = :user_id OR p.created_by_user_id IS NULL)
               AND p.category IS NOT NULL
               AND p.category <> ""
             ORDER BY p.category ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return array_values(array_filter(
            array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)),
            static fn (string $category): bool => DiscountPromotionCategory::isValid($category),
        ));
    }

    public function setActiveManualForUser(int $userId, int $promotionId, bool $active): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE discount_promotions
             SET is_active = :active
             WHERE id = :id
               AND created_by_user_id = :user_id
               AND source_type = "manual"'
        );
        $statement->execute([
            'active' => $active ? 1 : 0,
            'id' => $promotionId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function deleteManualForUser(int $userId, int $promotionId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM discount_promotions
             WHERE id = :id
               AND created_by_user_id = :user_id
               AND source_type = "manual"'
        );
        $statement->execute([
            'id' => $promotionId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function delete(int $promotionId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM discount_promotions WHERE id = :id');
        $statement->execute(['id' => $promotionId]);

        return $statement->rowCount() > 0;
    }

    private function selectSql(): string
    {
        return 'SELECT ' . self::COLUMNS . ',
                    m.name AS merchant_name,
                    m.category AS merchant_category
                FROM discount_promotions p
                LEFT JOIN discount_merchants m ON m.id = p.merchant_id';
    }

    /**
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     */
    private function addSearchFilter(array &$where, array &$params, string $search, string $prefix): void
    {
        $like = '%' . $this->escapeLike($search) . '%';
        $clauses = [];

        foreach ([
            'title' => 'p.title',
            'description' => 'p.description',
            'terms' => 'p.terms',
            'merchant' => 'm.name',
            'category' => 'p.category',
            'merchant_category' => 'm.category',
        ] as $suffix => $column) {
            $param = $prefix . '_' . $suffix;
            $clauses[] = $column . " LIKE :" . $param . " ESCAPE '!'";
            $params[$param] = $like;
        }

        $categoryMatches = (new DiscountPromotionCategory())->categoriesForSearch($search);

        if ($categoryMatches !== []) {
            $placeholders = [];

            foreach ($categoryMatches as $index => $category) {
                $param = $prefix . '_category_alias_' . $index;
                $placeholders[] = ':' . $param;
                $params[$param] = $category;
            }

            $clauses[] = 'p.category IN (' . implode(', ', $placeholders) . ')';
        }

        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }

    /**
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     */
    private function addCategoryFilter(array &$where, array &$params, mixed $category, string $prefix): void
    {
        if (!is_string($category)) {
            return;
        }

        $categories = DiscountPromotionCategory::filterCategories($category);

        if ($categories === []) {
            return;
        }

        $placeholders = [];

        foreach ($categories as $index => $value) {
            $param = $prefix . '_' . $index;
            $placeholders[] = ':' . $param;
            $params[$param] = $value;
        }

        $where[] = 'p.category IN (' . implode(', ', $placeholders) . ')';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
