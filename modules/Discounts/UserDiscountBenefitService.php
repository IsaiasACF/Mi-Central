<?php
declare(strict_types=1);

namespace Modules\Discounts;

use PDOException;

final class UserDiscountBenefitService
{
    private const NICKNAME_MAX_LENGTH = 180;
    private const NOTES_MAX_LENGTH = 5000;

    public function __construct(
        private readonly UserDiscountBenefitRepository $userBenefits,
        private readonly DiscountBenefitProgramService $programs,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $programId = $this->positiveId((int) ($input['benefit_program_id'] ?? 0), 'benefit_program_id');
        $this->programs->assertActive($programId);
        $existing = $this->userBenefits->findByProgramForUser($userId, $programId);

        if ($existing !== null) {
            if ((int) ($existing['active'] ?? 0) === 1) {
                throw new DiscountValidationException('Este beneficio ya esta agregado.');
            }

            $id = (int) $existing['id'];
            $this->userBenefits->update($userId, $id, [
                'nickname' => $this->optionalText($input['nickname'] ?? null, self::NICKNAME_MAX_LENGTH),
                'notes' => $this->optionalText($input['notes'] ?? null, self::NOTES_MAX_LENGTH),
                'active' => 1,
            ]);

            return $this->get($userId, $id) ?? [];
        }

        try {
            $id = $this->userBenefits->create($userId, [
                'benefit_program_id' => $programId,
                'nickname' => $this->optionalText($input['nickname'] ?? null, self::NICKNAME_MAX_LENGTH),
                'notes' => $this->optionalText($input['notes'] ?? null, self::NOTES_MAX_LENGTH),
                'active' => $this->boolFlag($input['active'] ?? true),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new DiscountValidationException('Este beneficio ya esta agregado.');
            }

            throw $exception;
        }

        return $this->get($userId, $id) ?? [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createProgramAndAssign(int $userId, array $input): array
    {
        $program = $this->programs->createOrFind($input);

        if ((int) ($program['active'] ?? 0) !== 1) {
            throw new DiscountValidationException('Programa de beneficio no encontrado.');
        }

        return $this->create($userId, [
            'benefit_program_id' => (int) $program['id'],
            'nickname' => $input['nickname'] ?? null,
            'notes' => $input['notes'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $userBenefitId): ?array
    {
        return $this->userBenefits->findForUser($userId, $this->positiveId($userBenefitId, 'id'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(int $userId): array
    {
        return $this->userBenefits->listForUser($userId, ['active' => true]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId): array
    {
        return $this->userBenefits->listForUser($userId);
    }

    public function delete(int $userId, int $userBenefitId): bool
    {
        return $this->userBenefits->delete($userId, $this->positiveId($userBenefitId, 'id'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $userBenefitId, array $input): ?array
    {
        $userBenefitId = $this->positiveId($userBenefitId, 'id');
        $current = $this->get($userId, $userBenefitId);

        if ($current === null) {
            return null;
        }

        $this->userBenefits->update($userId, $userBenefitId, [
            'nickname' => $this->optionalText($input['nickname'] ?? null, self::NICKNAME_MAX_LENGTH),
            'notes' => $this->optionalText($input['notes'] ?? null, self::NOTES_MAX_LENGTH),
            'active' => $this->boolFlag($input['active'] ?? $current['active'] ?? true),
        ]);

        return $this->get($userId, $userBenefitId);
    }

    public function deactivate(int $userId, int $userBenefitId): bool
    {
        $userBenefitId = $this->positiveId($userBenefitId, 'id');

        if ($this->get($userId, $userBenefitId) === null) {
            return false;
        }

        return $this->userBenefits->setActive($userId, $userBenefitId, false);
    }

    public function setProgramActive(int $userId, int $programId, bool $active): bool
    {
        $programId = $this->positiveId($programId, 'benefit_program_id');
        $this->programs->assertActive($programId);
        $existing = $this->userBenefits->findByProgramForUser($userId, $programId);

        if ($existing === null) {
            if (!$active) {
                return true;
            }

            $this->create($userId, [
                'benefit_program_id' => $programId,
                'nickname' => null,
                'notes' => null,
                'active' => 1,
            ]);

            return true;
        }

        if ((int) ($existing['active'] ?? 0) === ($active ? 1 : 0)) {
            return true;
        }

        return $this->userBenefits->setActive($userId, (int) $existing['id'], $active);
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        if ($value === '') {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($length > $maxLength) {
            throw new DiscountValidationException('Texto demasiado largo.');
        }

        return $value;
    }

    private function boolFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        if (is_string($value) && in_array($value, ['0', '1'], true)) {
            return (int) $value;
        }

        throw new DiscountValidationException('Estado invalido.');
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new DiscountValidationException('Identificador invalido: ' . $field . '.');
        }

        return $value;
    }
}
