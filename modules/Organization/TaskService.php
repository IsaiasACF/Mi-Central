<?php
declare(strict_types=1);

namespace Modules\Organization;

use App\Support\DateTimeHelper;

final class TaskService
{
    private const TITLE_MAX_LENGTH = 180;
    private const STATUSES = ['pending', 'completed'];

    private readonly DeadlineUrgencyService $urgency;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
        private readonly ?LabelService $labels = null,
        ?DeadlineUrgencyService $urgency = null,
    ) {
        $this->urgency = $urgency ?? new DeadlineUrgencyService($timezone);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $status = array_key_exists('status', $input) ? $this->status($input['status']) : 'pending';
        $data = [
            'space_id' => $this->optionalPositiveInt($input['space_id'] ?? null, 'space_id'),
            'category_id' => $this->optionalPositiveInt($input['category_id'] ?? null, 'category_id'),
            'project_id' => $this->optionalPositiveInt($input['project_id'] ?? null, 'project_id'),
            'parent_task_id' => $this->optionalPositiveInt($input['parent_task_id'] ?? null, 'parent_task_id'),
            'title' => $this->title($input['title'] ?? ''),
            'description' => $this->optionalText($input['description'] ?? null),
            'status' => $status,
            'priority' => 'normal',
            'starts_at' => $this->optionalDateTime($input['starts_at'] ?? null, 'starts_at'),
            'ends_at' => $this->optionalDateTime($input['ends_at'] ?? null, 'ends_at'),
            'due_at' => $this->optionalDateTime($input['due_at'] ?? null, 'due_at'),
            'completed_at' => $status === 'completed' ? DateTimeHelper::nowUtcStorage() : null,
            'position' => $this->position($input['position'] ?? null, $userId),
        ];
        $labelIds = $this->labels?->labelIdsFromInput($input) ?? [];

        $data = $this->withCoherentProjectSpace($userId, $data);
        $this->assertRelationshipsBelongToUser($userId, $data);
        $this->labels?->assertLabelIdsBelongToUser($userId, $labelIds);
        $this->assertDateRange($data['starts_at'], $data['ends_at']);

        $taskId = $this->tasks->create($userId, $data);
        $this->labels?->syncEntityLabels($userId, 'task', $taskId, $labelIds);

        return $this->get($userId, $taskId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $taskId): ?array
    {
        $task = $this->tasks->findByIdForUser($userId, $this->positiveId($taskId, 'id'));

        if ($task === null) {
            return null;
        }

        $task = $this->withDerivedDates($task);

        if ($this->labels !== null) {
            $task = $this->labels->attachLabelsToEntities($userId, 'task', [$task])[0] ?? $task;
        }

        return $task;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, array $filters = []): array
    {
        $normalized = [];

        foreach (['space_id', 'project_id', 'parent_task_id'] as $field) {
            if (!array_key_exists($field, $filters) || $filters[$field] === '' || $filters[$field] === null) {
                continue;
            }

            if ($field === 'space_id' && $filters[$field] === 'none') {
                $normalized['space_id_is_null'] = true;
                continue;
            }

            if (($field === 'project_id' || $field === 'parent_task_id') && $filters[$field] === 'none') {
                $normalized[$field . '_is_null'] = true;
                continue;
            }

            $normalized[$field] = $this->optionalPositiveInt($filters[$field], $field);
        }

        if (isset($normalized['space_id']) && !$this->tasks->spaceBelongsToUser($userId, $normalized['space_id'])) {
            throw new TaskValidationException('Relacion invalida.');
        }

        if (isset($normalized['project_id']) && !$this->tasks->projectBelongsToUser($userId, $normalized['project_id'])) {
            throw new TaskValidationException('Relacion invalida.');
        }

        if (isset($normalized['parent_task_id']) && !$this->tasks->taskBelongsToUser($userId, $normalized['parent_task_id'])) {
            throw new TaskValidationException('Relacion invalida.');
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $normalized['status'] = $this->status($filters['status']);
        }

        if (isset($filters['due_from']) && $filters['due_from'] !== '') {
            $normalized['due_from'] = $this->optionalDateTime($filters['due_from'], 'due_from');
        }

        if (isset($filters['due_to']) && $filters['due_to'] !== '') {
            $normalized['due_to'] = $this->optionalDateTime($filters['due_to'], 'due_to');
        }

        if (isset($filters['due_before']) && $filters['due_before'] !== '') {
            $normalized['due_before'] = $this->optionalDateTime($filters['due_before'], 'due_before');
        }

        if (isset($filters['label_id']) && $filters['label_id'] !== '' && $filters['label_id'] !== 'all') {
            $labelId = $this->optionalPositiveInt($filters['label_id'], 'label_id');

            if ($this->labels === null || $labelId === null || $this->labels->get($userId, $labelId) === null) {
                throw new TaskValidationException('Relacion invalida.');
            }

            $normalized['label_id'] = $labelId;
        }

        $tasks = array_map(
            fn (array $task): array => $this->withDerivedDates($task),
            $this->tasks->listForUser($userId, $normalized),
        );

        if ($this->labels !== null) {
            $tasks = $this->labels->attachLabelsToEntities($userId, 'task', $tasks);
        }

        if (($filters['sort'] ?? 'deadline') === 'deadline') {
            $this->sortByDeadline($tasks, 'due_at');
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $taskId, array $input): ?array
    {
        $taskId = $this->positiveId($taskId, 'id');
        $currentTask = $this->tasks->findByIdForUser($userId, $taskId);

        if ($currentTask === null) {
            return null;
        }

        $data = [];

        foreach (['space_id', 'category_id', 'project_id', 'parent_task_id'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $data[$field] = $this->optionalPositiveInt($input[$field], $field);
        }

        if (array_key_exists('title', $input)) {
            $data['title'] = $this->title($input['title']);
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = $this->optionalText($input['description']);
        }

        if (array_key_exists('starts_at', $input)) {
            $data['starts_at'] = $this->optionalDateTime($input['starts_at'], 'starts_at');
        }

        if (array_key_exists('ends_at', $input)) {
            $data['ends_at'] = $this->optionalDateTime($input['ends_at'], 'ends_at');
        }

        if (array_key_exists('due_at', $input)) {
            $data['due_at'] = $this->optionalDateTime($input['due_at'], 'due_at');
        }

        if (array_key_exists('position', $input)) {
            $data['position'] = $this->position($input['position'], $userId);
        }

        if (array_key_exists('status', $input)) {
            $data['status'] = $this->status($input['status']);
            $data['completed_at'] = $data['status'] === 'completed' ? DateTimeHelper::nowUtcStorage() : null;
        }

        if (!array_key_exists('project_id', $data) && ($currentTask['project_id'] ?? null) !== null) {
            $data['project_id'] = (int) $currentTask['project_id'];
        }

        $data = $this->withCoherentProjectSpace($userId, $data);
        $this->assertRelationshipsBelongToUser($userId, $data);
        $this->assertNoCycle($userId, $taskId, $data['parent_task_id'] ?? null);
        $this->assertDateRange(
            array_key_exists('starts_at', $data) ? $data['starts_at'] : ($currentTask['starts_at'] ?? null),
            array_key_exists('ends_at', $data) ? $data['ends_at'] : ($currentTask['ends_at'] ?? null)
        );
        $labelIds = array_key_exists('label_ids', $input) && $this->labels !== null
            ? $this->labels->labelIdsFromInput($input)
            : null;

        if ($labelIds !== null) {
            $this->labels?->assertLabelIdsBelongToUser($userId, $labelIds);
        }

        $this->tasks->update($userId, $taskId, $data);

        if ($labelIds !== null) {
            $this->labels?->syncEntityLabels($userId, 'task', $taskId, $labelIds);
        }

        return $this->get($userId, $taskId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function complete(int $userId, int $taskId): ?array
    {
        return $this->update($userId, $taskId, ['status' => 'completed']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reopen(int $userId, int $taskId): ?array
    {
        return $this->update($userId, $taskId, ['status' => 'pending']);
    }

    public function delete(int $userId, int $taskId): bool
    {
        return $this->tasks->delete($userId, $this->positiveId($taskId, 'id'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertRelationshipsBelongToUser(int $userId, array $data): void
    {
        if (isset($data['space_id']) && !$this->tasks->spaceBelongsToUser($userId, (int) $data['space_id'])) {
            throw new TaskValidationException('Relacion invalida.');
        }

        if (isset($data['category_id']) && !$this->tasks->categoryBelongsToUser($userId, (int) $data['category_id'])) {
            throw new TaskValidationException('Relacion invalida.');
        }

        if (isset($data['project_id']) && !$this->tasks->projectBelongsToUser($userId, (int) $data['project_id'])) {
            throw new TaskValidationException('Relacion invalida.');
        }

        if (isset($data['parent_task_id']) && !$this->tasks->taskBelongsToUser($userId, (int) $data['parent_task_id'])) {
            throw new TaskValidationException('Relacion invalida.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withCoherentProjectSpace(int $userId, array $data): array
    {
        if (!isset($data['project_id'])) {
            return $data;
        }

        $projectSpaceId = $this->tasks->projectSpaceIdForUser($userId, (int) $data['project_id']);

        if ($projectSpaceId === null) {
            throw new TaskValidationException('Relacion invalida.');
        }

        if (!isset($data['space_id']) || $data['space_id'] === null) {
            $data['space_id'] = $projectSpaceId;
            return $data;
        }

        if ((int) $data['space_id'] !== $projectSpaceId) {
            throw new TaskValidationException('El espacio de la tarea no coincide con el proyecto.');
        }

        return $data;
    }

    private function assertNoCycle(int $userId, int $taskId, ?int $parentTaskId): void
    {
        if ($parentTaskId === null) {
            return;
        }

        if ($parentTaskId === $taskId) {
            throw new TaskValidationException('Una tarea no puede ser su propio padre.');
        }

        $visited = [];
        $currentId = $parentTaskId;

        while ($currentId !== null) {
            if ($currentId === $taskId || isset($visited[$currentId])) {
                throw new TaskValidationException('Jerarquia de tareas invalida.');
            }

            $visited[$currentId] = true;
            $currentId = $this->tasks->findParentTaskIdForUser($userId, $currentId);
        }
    }

    private function assertDateRange(?string $startsAt, ?string $endsAt): void
    {
        if ($startsAt === null || $endsAt === null) {
            return;
        }

        if (strtotime($endsAt) < strtotime($startsAt)) {
            throw new TaskValidationException('La fecha de fin no puede ser anterior al inicio.');
        }
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function withDerivedDates(array $task): array
    {
        foreach (['starts_at', 'ends_at', 'due_at', 'completed_at'] as $field) {
            $value = is_string($task[$field] ?? null) ? (string) $task[$field] : null;
            $task[$field . '_local'] = DateTimeHelper::utcStorageToLocalStorage($value, $this->timezone);
            $task[$field . '_input'] = DateTimeHelper::utcStorageToLocalInput($value, $this->timezone);
        }

        $task['urgency'] = $this->urgency->forTask(
            is_string($task['due_at'] ?? null) ? (string) $task['due_at'] : null,
            (string) ($task['status'] ?? 'pending'),
        );

        return $task;
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

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $this->positiveId($value, $field);
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return $this->positiveId((int) $value, $field);
        }

        throw new TaskValidationException("Identificador invalido: {$field}.");
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new TaskValidationException("Identificador invalido: {$field}.");
        }

        return $value;
    }

    private function position(mixed $value, int $userId): int
    {
        if ($value === null || $value === '') {
            return $this->tasks->nextPosition($userId);
        }

        if (is_int($value)) {
            $position = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            $position = (int) $value;
        } else {
            throw new TaskValidationException('Posicion invalida.');
        }

        if ($position < 0) {
            throw new TaskValidationException('Posicion invalida.');
        }

        return $position;
    }

    private function optionalDateTime(mixed $value, string $field): ?string
    {
        return DateTimeHelper::optionalLocalInputToUtcStorage($value, $field, $this->timezone);
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     */
    private function sortByDeadline(array &$tasks, string $field): void
    {
        usort($tasks, static function (array $left, array $right) use ($field): int {
            $leftCompleted = ($left['status'] ?? '') === 'completed';
            $rightCompleted = ($right['status'] ?? '') === 'completed';

            if ($leftCompleted !== $rightCompleted) {
                return $leftCompleted <=> $rightCompleted;
            }

            $leftDeadline = $left[$field] ?? ($left['ends_at'] ?? null);
            $rightDeadline = $right[$field] ?? ($right['ends_at'] ?? null);
            $leftHasDeadline = is_string($leftDeadline) && $leftDeadline !== '';
            $rightHasDeadline = is_string($rightDeadline) && $rightDeadline !== '';

            if ($leftHasDeadline !== $rightHasDeadline) {
                return $leftHasDeadline ? -1 : 1;
            }

            if ($leftHasDeadline && $rightHasDeadline && $leftDeadline !== $rightDeadline) {
                return strcmp((string) $leftDeadline, (string) $rightDeadline);
            }

            return [(int) ($left['position'] ?? 0), (int) ($left['id'] ?? 0)] <=> [(int) ($right['position'] ?? 0), (int) ($right['id'] ?? 0)];
        });
    }
}
