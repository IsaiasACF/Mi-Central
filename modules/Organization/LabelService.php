<?php
declare(strict_types=1);

namespace Modules\Organization;

final class LabelService
{
    private const NAME_MAX_LENGTH = 120;

    public function __construct(private readonly LabelRepository $labels)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $name = $this->name($input['name'] ?? '');
        $normalizedName = $this->normalizedName($name);
        $color = $this->color($input['color'] ?? '');

        if ($this->labels->normalizedNameExists($userId, $normalizedName)) {
            throw new TaskValidationException('Ya existe una etiqueta con ese nombre.');
        }

        $labelId = $this->labels->create($userId, $name, $normalizedName, $color);

        return $this->get($userId, $labelId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $labelId): ?array
    {
        return $this->labels->findByIdForUser($userId, $this->positiveId($labelId, 'id'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId): array
    {
        return $this->labels->listForUser($userId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $labelId, array $input): ?array
    {
        $labelId = $this->positiveId($labelId, 'id');

        if ($this->labels->findByIdForUser($userId, $labelId) === null) {
            return null;
        }

        $name = $this->name($input['name'] ?? '');
        $normalizedName = $this->normalizedName($name);
        $color = $this->color($input['color'] ?? '');

        if ($this->labels->normalizedNameExists($userId, $normalizedName, $labelId)) {
            throw new TaskValidationException('Ya existe una etiqueta con ese nombre.');
        }

        $this->labels->update($userId, $labelId, $name, $normalizedName, $color);

        return $this->get($userId, $labelId);
    }

    public function delete(int $userId, int $labelId): bool
    {
        return $this->labels->delete($userId, $this->positiveId($labelId, 'id'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, int>
     */
    public function labelIdsFromInput(array $input): array
    {
        $raw = $input['label_ids'] ?? [];

        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }

        if (!is_array($raw)) {
            throw new TaskValidationException('Etiquetas invalidas.');
        }

        $ids = [];

        foreach ($raw as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
                $id = (int) $value;
            } else {
                throw new TaskValidationException('Etiquetas invalidas.');
            }

            if ($id <= 0) {
                throw new TaskValidationException('Etiquetas invalidas.');
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @return array<int, array<string, mixed>>
     */
    public function attachLabelsToEntities(int $userId, string $entityType, array $entities): array
    {
        if ($entities === []) {
            return [];
        }

        $entityIds = array_map(static fn (array $entity): int => (int) ($entity['id'] ?? 0), $entities);
        $labelsByEntity = $this->labels->labelsForEntities($userId, $entityType, $entityIds);

        return array_map(static function (array $entity) use ($labelsByEntity): array {
            $entity['labels'] = $labelsByEntity[(int) ($entity['id'] ?? 0)] ?? [];

            return $entity;
        }, $entities);
    }

    /**
     * @param array<int, int> $labelIds
     */
    public function syncEntityLabels(int $userId, string $entityType, int $entityId, array $labelIds): void
    {
        $this->assertLabelIdsBelongToUser($userId, $labelIds);

        $this->labels->syncForEntity($userId, $entityType, $entityId, $labelIds);
    }

    /**
     * @param array<int, int> $labelIds
     */
    public function assertLabelIdsBelongToUser(int $userId, array $labelIds): void
    {
        if (!$this->labels->allLabelsBelongToUser($userId, $labelIds)) {
            throw new TaskValidationException('Relacion invalida.');
        }
    }

    private function name(mixed $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        if ($name === '' || $length > self::NAME_MAX_LENGTH) {
            throw new TaskValidationException('Nombre de etiqueta invalido.');
        }

        return $name;
    }

    private function normalizedName(string $name): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private function color(mixed $color): string
    {
        $color = strtoupper(trim((string) $color));

        if (preg_match('/\A#[0-9A-F]{6}\z/', $color) !== 1) {
            throw new TaskValidationException('Color de etiqueta invalido.');
        }

        return $color;
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new TaskValidationException("Identificador invalido: {$field}.");
        }

        return $value;
    }
}
