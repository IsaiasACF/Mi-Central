<?php
declare(strict_types=1);

namespace Modules\Organization;

final class ProjectService
{
    private const TITLE_MAX_LENGTH = 180;
    private const STATUSES = ['active', 'completed', 'archived'];

    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $data = [
            'space_id' => $this->spaceId($userId, $input['space_id'] ?? null),
            'title' => $this->title($input['title'] ?? ''),
            'description' => $this->optionalText($input['description'] ?? null),
            'status' => $this->status($input['status'] ?? 'active'),
            'starts_on' => $this->optionalDate($input['starts_on'] ?? null, 'starts_on'),
            'due_on' => $this->optionalDate($input['due_on'] ?? null, 'due_on'),
        ];
        $this->assertDateRange($data['starts_on'], $data['due_on']);

        $projectId = $this->projects->create($userId, $data);

        return $this->get($userId, $projectId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $projectId): ?array
    {
        return $this->projects->findByIdForUser($userId, $this->positiveId($projectId, 'id'));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, array $filters = []): array
    {
        $normalized = [];

        if (($filters['space_id'] ?? null) === 'none') {
            return [];
        }

        if (isset($filters['space_id']) && $filters['space_id'] !== '' && $filters['space_id'] !== null && $filters['space_id'] !== 'none') {
            $normalized['space_id'] = $this->spaceId($userId, $filters['space_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $normalized['status'] = $this->projectStatusFromOrganizationStatus((string) $filters['status']);
        }

        if (isset($filters['due_from']) && $filters['due_from'] !== '') {
            $normalized['due_from'] = substr((string) $filters['due_from'], 0, 10);
        }

        if (isset($filters['due_before']) && $filters['due_before'] !== '') {
            $normalized['due_before'] = substr((string) $filters['due_before'], 0, 10);
        }

        return $this->projects->listForUser($userId, $normalized);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $projectId, array $input): ?array
    {
        $projectId = $this->positiveId($projectId, 'id');
        $currentProject = $this->projects->findByIdForUser($userId, $projectId);

        if ($currentProject === null) {
            return null;
        }

        $data = [];

        if (array_key_exists('space_id', $input)) {
            $data['space_id'] = $this->spaceId($userId, $input['space_id']);
        }

        if (array_key_exists('title', $input)) {
            $data['title'] = $this->title($input['title']);
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = $this->optionalText($input['description']);
        }

        if (array_key_exists('status', $input)) {
            $data['status'] = $this->status($input['status']);
        }

        if (array_key_exists('starts_on', $input)) {
            $data['starts_on'] = $this->optionalDate($input['starts_on'], 'starts_on');
        }

        if (array_key_exists('due_on', $input)) {
            $data['due_on'] = $this->optionalDate($input['due_on'], 'due_on');
        }

        $this->assertDateRange(
            array_key_exists('starts_on', $data) ? $data['starts_on'] : ($currentProject['starts_on'] ?? null),
            array_key_exists('due_on', $data) ? $data['due_on'] : ($currentProject['due_on'] ?? null)
        );
        $this->projects->update($userId, $projectId, $data);

        if (array_key_exists('space_id', $data) && (int) $currentProject['space_id'] !== (int) $data['space_id']) {
            $this->projects->syncTaskSpacesForProject($userId, $projectId, (int) $data['space_id']);
        }

        return $this->get($userId, $projectId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function complete(int $userId, int $projectId): ?array
    {
        return $this->update($userId, $projectId, ['status' => 'completed']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function archive(int $userId, int $projectId): ?array
    {
        return $this->update($userId, $projectId, ['status' => 'archived']);
    }

    public function delete(int $userId, int $projectId): bool
    {
        return $this->projects->delete($userId, $this->positiveId($projectId, 'id'));
    }

    private function projectStatusFromOrganizationStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'active',
            'completed' => 'completed',
            default => $this->status($status),
        };
    }

    private function title(mixed $title): string
    {
        $title = trim((string) $title);
        $length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);

        if ($title === '' || $length > self::TITLE_MAX_LENGTH) {
            throw new TaskValidationException('Titulo invalido.');
        }

        return $title;
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function status(mixed $status): string
    {
        $status = (string) $status;

        if (!in_array($status, self::STATUSES, true)) {
            throw new TaskValidationException('Estado invalido.');
        }

        return $status;
    }

    private function spaceId(int $userId, mixed $spaceId): int
    {
        if (!is_int($spaceId) && !(is_string($spaceId) && preg_match('/\A[1-9][0-9]*\z/', $spaceId) === 1)) {
            throw new TaskValidationException('Espacio requerido.');
        }

        $spaceId = (int) $spaceId;

        if (!$this->projects->spaceBelongsToUser($userId, $spaceId)) {
            throw new TaskValidationException('Relacion invalida.');
        }

        return $spaceId;
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new TaskValidationException("Fecha invalida: {$field}.");
        }

        return $value;
    }

    private function assertDateRange(?string $startsOn, ?string $dueOn): void
    {
        if ($startsOn === null || $dueOn === null) {
            return;
        }

        if (strcmp($dueOn, $startsOn) < 0) {
            throw new TaskValidationException('La fecha limite no puede ser anterior al inicio.');
        }
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new TaskValidationException("Identificador invalido: {$field}.");
        }

        return $value;
    }
}
