<?php
declare(strict_types=1);

namespace Modules\Organization;

use App\Support\DateTimeHelper;
use DateTimeImmutable;

final class ReminderService
{
    private const TITLE_MAX_LENGTH = 180;
    private const STATUSES = ['pending', 'dismissed', 'completed'];
    private const RECURRENCE_TYPES = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

    public function __construct(
        private readonly ReminderRepository $reminders,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
    )
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $remindAt = $this->dateTime($input, 'remind_at');
        $recurrenceType = $this->recurrenceType($input['recurrence_type'] ?? 'none');
        $recurrenceInterval = $this->recurrenceInterval($input['recurrence_interval'] ?? 1);
        $recurrenceUntil = $this->recurrenceUntil($input['recurrence_until'] ?? null);
        $this->assertRecurrenceUntilAllowsFirstOccurrence($remindAt, $recurrenceUntil);

        $data = [
            'task_id' => $this->optionalPositiveInt($input['task_id'] ?? null, 'task_id'),
            'project_id' => $this->optionalPositiveInt($input['project_id'] ?? null, 'project_id'),
            'title' => $this->title($input['title'] ?? ''),
            'description' => $this->optionalText($input['description'] ?? null),
            'remind_at' => $remindAt,
            'status' => $this->status($input['status'] ?? 'pending'),
            'recurrence_type' => $recurrenceType,
            'recurrence_interval' => $recurrenceInterval,
            'recurrence_until' => $recurrenceUntil,
            'next_remind_at' => $remindAt,
        ];

        $this->assertTarget($userId, $data['task_id'], $data['project_id']);

        $reminderId = $this->reminders->create($userId, $data);

        return $this->get($userId, $reminderId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $reminderId): ?array
    {
        $reminder = $this->reminders->findByIdForUser($userId, $this->positiveId($reminderId, 'id'));

        return $reminder === null ? null : $this->withDerivedState($reminder);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, array $filters = []): array
    {
        $normalized = [];
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : 'pending';

        if ($status === 'done') {
            $normalized['done_statuses'] = true;
        } elseif ($status !== 'all') {
            $normalized['status'] = $this->status($status);
        }

        if (isset($filters['remind_from']) && $filters['remind_from'] !== '') {
            $normalized['remind_from'] = $this->dateTime(['remind_at' => $filters['remind_from']], 'remind_from');
        }

        if (isset($filters['remind_before']) && $filters['remind_before'] !== '') {
            $normalized['remind_before'] = $this->dateTime(['remind_at' => $filters['remind_before']], 'remind_before');
        }

        if (isset($filters['limit'])) {
            $normalized['limit'] = $this->positiveId((int) $filters['limit'], 'limit');
        }

        return array_map(
            fn (array $reminder): array => $this->withDerivedState($reminder),
            $this->reminders->listForUser($userId, $normalized),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $reminderId, array $input): ?array
    {
        $reminderId = $this->positiveId($reminderId, 'id');
        $current = $this->reminders->findByIdForUser($userId, $reminderId);

        if ($current === null) {
            return null;
        }

        $data = [];

        if (array_key_exists('task_id', $input)) {
            $data['task_id'] = $this->optionalPositiveInt($input['task_id'], 'task_id');
        }

        if (array_key_exists('project_id', $input)) {
            $data['project_id'] = $this->optionalPositiveInt($input['project_id'], 'project_id');
        }

        if (array_key_exists('title', $input)) {
            $data['title'] = $this->title($input['title']);
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = $this->optionalText($input['description']);
        }

        if (array_key_exists('remind_at', $input) || array_key_exists('remind_date', $input) || array_key_exists('remind_time', $input)) {
            $data['remind_at'] = $this->dateTime($input, 'remind_at');
        }

        if (array_key_exists('status', $input)) {
            $data['status'] = $this->status($input['status']);
        }

        $recurrenceChanged = false;

        if (array_key_exists('recurrence_type', $input)) {
            $data['recurrence_type'] = $this->recurrenceType($input['recurrence_type']);
            $recurrenceChanged = true;
        }

        if (array_key_exists('recurrence_interval', $input)) {
            $data['recurrence_interval'] = $this->recurrenceInterval($input['recurrence_interval']);
            $recurrenceChanged = true;
        }

        if (array_key_exists('recurrence_until', $input)) {
            $data['recurrence_until'] = $this->recurrenceUntil($input['recurrence_until']);
            $recurrenceChanged = true;
        }

        $taskId = array_key_exists('task_id', $data) ? $data['task_id'] : ($current['task_id'] === null ? null : (int) $current['task_id']);
        $projectId = array_key_exists('project_id', $data) ? $data['project_id'] : ($current['project_id'] === null ? null : (int) $current['project_id']);
        $this->assertTarget($userId, $taskId, $projectId);

        if (array_key_exists('remind_at', $data) || $recurrenceChanged) {
            $remindAt = (string) ($data['remind_at'] ?? $current['remind_at']);
            $recurrenceType = (string) ($data['recurrence_type'] ?? ($current['recurrence_type'] ?? 'none'));
            $recurrenceInterval = (int) ($data['recurrence_interval'] ?? ($current['recurrence_interval'] ?? 1));
            $recurrenceUntil = array_key_exists('recurrence_until', $data)
                ? $data['recurrence_until']
                : ($current['recurrence_until'] ?? null);
            $this->assertRecurrenceUntilAllowsFirstOccurrence($remindAt, is_string($recurrenceUntil) ? $recurrenceUntil : null);
            $data['next_remind_at'] = $remindAt;
        }

        $this->reminders->update($userId, $reminderId, $data);

        return $this->get($userId, $reminderId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function complete(int $userId, int $reminderId): ?array
    {
        return $this->finishOccurrence($userId, $reminderId, 'completed');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dismiss(int $userId, int $reminderId): ?array
    {
        return $this->finishOccurrence($userId, $reminderId, 'dismissed');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function stopRecurrence(int $userId, int $reminderId): ?array
    {
        $reminderId = $this->positiveId($reminderId, 'id');
        $current = $this->reminders->findByIdForUser($userId, $reminderId);

        if ($current === null) {
            return null;
        }

        $this->reminders->update($userId, $reminderId, [
            'status' => 'completed',
            'recurrence_type' => 'none',
            'recurrence_interval' => 1,
            'recurrence_until' => null,
            'next_remind_at' => null,
        ]);

        return $this->get($userId, $reminderId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function advanceOccurrence(int $userId, int $reminderId): ?array
    {
        return $this->finishOccurrence($userId, $reminderId, 'completed');
    }

    public function delete(int $userId, int $reminderId): bool
    {
        return $this->reminders->delete($userId, $this->positiveId($reminderId, 'id'));
    }

    public function calculateNextOccurrence(
        string $currentOccurrenceUtc,
        string $recurrenceType,
        int $recurrenceInterval,
        ?string $recurrenceUntilUtc = null,
        ?string $anchorUtc = null,
    ): ?string {
        $recurrenceType = $this->recurrenceType($recurrenceType);
        $recurrenceInterval = $this->recurrenceInterval($recurrenceInterval);

        if ($recurrenceType === 'none') {
            return null;
        }

        $current = DateTimeHelper::utcStorageToLocalDateTime($currentOccurrenceUtc, $this->timezone);
        $anchor = DateTimeHelper::utcStorageToLocalDateTime($anchorUtc ?? $currentOccurrenceUtc, $this->timezone);
        $next = match ($recurrenceType) {
            'daily' => $current->modify('+' . $recurrenceInterval . ' days'),
            'weekly' => $current->modify('+' . $recurrenceInterval . ' weeks'),
            'monthly' => $this->addMonthsClamped($current, $recurrenceInterval, $anchor),
            'yearly' => $this->addYearsClamped($current, $recurrenceInterval, $anchor),
            default => null,
        };

        if (!$next instanceof DateTimeImmutable) {
            return null;
        }

        if ($recurrenceUntilUtc !== null) {
            $until = DateTimeHelper::utcStorageToLocalDateTime($recurrenceUntilUtc, $this->timezone);

            if ($next > $until) {
                return null;
            }
        }

        return $next->setTimezone(DateTimeHelper::utcTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function finishOccurrence(int $userId, int $reminderId, string $terminalStatus): ?array
    {
        $reminderId = $this->positiveId($reminderId, 'id');
        $current = $this->reminders->findByIdForUser($userId, $reminderId);

        if ($current === null) {
            return null;
        }

        $recurrenceType = (string) ($current['recurrence_type'] ?? 'none');

        if ($recurrenceType === 'none') {
            return $this->update($userId, $reminderId, ['status' => $terminalStatus]);
        }

        $next = $this->calculateNextOccurrence(
            (string) ($current['next_remind_at'] ?? $current['remind_at']),
            $recurrenceType,
            (int) ($current['recurrence_interval'] ?? 1),
            is_string($current['recurrence_until'] ?? null) ? (string) $current['recurrence_until'] : null,
            (string) $current['remind_at'],
        );

        $this->reminders->update($userId, $reminderId, [
            'status' => $next === null ? $terminalStatus : 'pending',
            'next_remind_at' => $next,
        ]);

        return $this->get($userId, $reminderId);
    }

    /**
     * @param array<string, mixed> $reminder
     * @return array<string, mixed>
     */
    private function withDerivedState(array $reminder): array
    {
        $local = DateTimeHelper::utcStorageToLocalDateTime((string) $reminder['remind_at'], $this->timezone);
        $occurrenceValue = is_string($reminder['next_remind_at'] ?? null) && $reminder['next_remind_at'] !== ''
            ? (string) $reminder['next_remind_at']
            : (string) $reminder['remind_at'];
        $occurrence = DateTimeHelper::utcStorageToLocalDateTime($occurrenceValue, $this->timezone);
        $now = DateTimeHelper::nowLocal($this->timezone);

        $reminder['remind_at_local'] = $local->format('Y-m-d H:i:s');
        $reminder['remind_date_local'] = $local->format('Y-m-d');
        $reminder['remind_time_local'] = $local->format('H:i');
        $reminder['occurrence_at_local'] = $occurrence->format('Y-m-d H:i:s');
        $reminder['occurrence_date_local'] = $occurrence->format('Y-m-d');
        $reminder['occurrence_time_local'] = $occurrence->format('H:i');
        $reminder['next_remind_at_local'] = $reminder['next_remind_at'] === null ? null : $occurrence->format('Y-m-d H:i:s');
        $reminder['recurrence_until_local'] = is_string($reminder['recurrence_until'] ?? null) && $reminder['recurrence_until'] !== ''
            ? DateTimeHelper::utcStorageToLocalDateTime((string) $reminder['recurrence_until'], $this->timezone)->format('Y-m-d')
            : null;
        $reminder['is_recurring'] = ($reminder['recurrence_type'] ?? 'none') !== 'none';
        $reminder['recurrence_label'] = $this->recurrenceLabel(
            (string) ($reminder['recurrence_type'] ?? 'none'),
            (int) ($reminder['recurrence_interval'] ?? 1),
        );
        $reminder['is_overdue'] = ($reminder['status'] ?? null) === 'pending' && $occurrence < $now;
        $reminder['target_type'] = $reminder['task_id'] !== null ? 'task' : ($reminder['project_id'] !== null ? 'project' : 'none');
        $reminder['target_title'] = $reminder['task_id'] !== null
            ? ($reminder['task_title'] ?? null)
            : ($reminder['project_id'] !== null ? ($reminder['project_title'] ?? null) : null);
        $reminder['target_project_id'] = $reminder['task_id'] !== null && $reminder['task_project_id'] !== null
            ? (int) $reminder['task_project_id']
            : null;

        return $reminder;
    }

    private function assertTarget(int $userId, ?int $taskId, ?int $projectId): void
    {
        if ($taskId !== null && $projectId !== null) {
            throw new TaskValidationException('Un recordatorio no puede pertenecer a tarea y proyecto a la vez.');
        }

        if ($taskId !== null && !$this->reminders->taskBelongsToUser($userId, $taskId)) {
            throw new TaskValidationException('Relacion invalida.');
        }

        if ($projectId !== null && !$this->reminders->projectBelongsToUser($userId, $projectId)) {
            throw new TaskValidationException('Relacion invalida.');
        }
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

    private function recurrenceType(mixed $type): string
    {
        $type = (string) $type;

        if (!in_array($type, self::RECURRENCE_TYPES, true)) {
            throw new TaskValidationException('Recurrencia invalida.');
        }

        return $type;
    }

    private function recurrenceInterval(mixed $interval): int
    {
        if (is_int($interval)) {
            $value = $interval;
        } elseif (is_string($interval) && preg_match('/\A[1-9][0-9]*\z/', $interval) === 1) {
            $value = (int) $interval;
        } else {
            throw new TaskValidationException('Intervalo de recurrencia invalido.');
        }

        if ($value < 1 || $value > 999) {
            throw new TaskValidationException('Intervalo de recurrencia invalido.');
        }

        return $value;
    }

    private function recurrenceUntil(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return DateTimeHelper::localDateEndToUtcStorage($value, 'recurrence_until', $this->timezone);
    }

    private function assertRecurrenceUntilAllowsFirstOccurrence(string $remindAtUtc, ?string $recurrenceUntilUtc): void
    {
        if ($recurrenceUntilUtc === null) {
            return;
        }

        $remindAt = new DateTimeImmutable($remindAtUtc, DateTimeHelper::utcTimezone());
        $until = new DateTimeImmutable($recurrenceUntilUtc, DateTimeHelper::utcTimezone());

        if ($until < $remindAt) {
            throw new TaskValidationException('La fecha limite de recurrencia no puede ser anterior al recordatorio.');
        }
    }

    private function recurrenceLabel(string $type, int $interval): string
    {
        if ($type === 'none') {
            return 'No repetir';
        }

        $singular = match ($type) {
            'daily' => 'dia',
            'weekly' => 'semana',
            'monthly' => 'mes',
            'yearly' => 'ano',
            default => '',
        };
        $plural = match ($type) {
            'daily' => 'dias',
            'weekly' => 'semanas',
            'monthly' => 'meses',
            'yearly' => 'anos',
            default => '',
        };

        return $interval === 1 ? 'Cada ' . $singular : 'Cada ' . $interval . ' ' . $plural;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function dateTime(array $input, string $field): string
    {
        $value = $input['remind_at'] ?? null;

        if (($value === null || $value === '') && isset($input['remind_date'], $input['remind_time'])) {
            $value = trim((string) $input['remind_date']) . ' ' . trim((string) $input['remind_time']);
        }

        if ($value === null || $value === '') {
            throw new TaskValidationException("Fecha requerida: {$field}.");
        }

        return DateTimeHelper::localInputToUtcStorage($value, $field, $this->timezone);
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

    private function addMonthsClamped(DateTimeImmutable $current, int $months, DateTimeImmutable $anchor): DateTimeImmutable
    {
        $target = $current
            ->modify('first day of this month')
            ->setTime((int) $anchor->format('H'), (int) $anchor->format('i'), (int) $anchor->format('s'))
            ->modify('+' . $months . ' months');
        $day = min((int) $anchor->format('j'), (int) $target->format('t'));

        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'), $day);
    }

    private function addYearsClamped(DateTimeImmutable $current, int $years, DateTimeImmutable $anchor): DateTimeImmutable
    {
        $year = (int) $current->format('Y') + $years;
        $month = (int) $anchor->format('m');
        $probe = $current
            ->setDate($year, $month, 1)
            ->setTime((int) $anchor->format('H'), (int) $anchor->format('i'), (int) $anchor->format('s'));
        $day = min((int) $anchor->format('j'), (int) $probe->format('t'));

        return $probe->setDate($year, $month, $day);
    }
}
