<?php
declare(strict_types=1);

namespace Modules\Discounts;

use PDO;

final class UserDiscountBenefitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $userId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_discount_benefits (
                user_id,
                benefit_program_id,
                nickname,
                notes,
                active
            ) VALUES (
                :user_id,
                :benefit_program_id,
                :nickname,
                :notes,
                :active
            )'
        );
        $statement->execute([
            'user_id' => $userId,
            'benefit_program_id' => $data['benefit_program_id'],
            'nickname' => $data['nickname'],
            'notes' => $data['notes'],
            'active' => $data['active'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $userId, int $userBenefitId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE ub.id = :id AND ub.user_id = :user_id
             LIMIT 1');
        $statement->execute([
            'id' => $userBenefitId,
            'user_id' => $userId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function userHasProgram(int $userId, int $programId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM user_discount_benefits
             WHERE user_id = :user_id AND benefit_program_id = :benefit_program_id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'benefit_program_id' => $programId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProgramForUser(int $userId, int $programId): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE ub.user_id = :user_id AND ub.benefit_program_id = :benefit_program_id
             LIMIT 1');
        $statement->execute([
            'user_id' => $userId,
            'benefit_program_id' => $programId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, array $filters = []): array
    {
        $where = ['ub.user_id = :user_id'];
        $params = ['user_id' => $userId];

        if (($filters['active'] ?? null) === true) {
            $where[] = 'ub.active = 1';
        }

        $statement = $this->pdo->prepare($this->selectSql() . '
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ub.active DESC, bp.provider_name ASC, bp.name ASC, ub.id ASC');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function delete(int $userId, int $userBenefitId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_discount_benefits WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $userBenefitId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $userId, int $userBenefitId, array $data): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_discount_benefits
             SET nickname = :nickname,
                 notes = :notes,
                 active = :active
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'nickname' => $data['nickname'],
            'notes' => $data['notes'],
            'active' => $data['active'],
            'id' => $userBenefitId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function setActive(int $userId, int $userBenefitId, bool $active): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_discount_benefits
             SET active = :active
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'active' => $active ? 1 : 0,
            'id' => $userBenefitId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    }

    private function selectSql(): string
    {
        return 'SELECT
                    ub.id,
                    ub.user_id,
                    ub.benefit_program_id,
                    ub.nickname,
                    ub.notes,
                    ub.active,
                    ub.created_at,
                    ub.updated_at,
                    bp.provider_name,
                    bp.name AS benefit_name,
                    bp.benefit_type,
                    bp.product_name,
                    bp.active AS benefit_program_active
                FROM user_discount_benefits ub
                INNER JOIN discount_benefit_programs bp ON bp.id = ub.benefit_program_id';
    }
}
