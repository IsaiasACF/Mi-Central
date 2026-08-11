<?php
declare(strict_types=1);

namespace Modules\Friends;

use DateTimeImmutable;
use DateTimeZone;

final class FriendScheduleResolver
{
    public function __construct(
        private readonly FriendScheduleService $schedules,
        private readonly string $timezone = 'America/Santiago',
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function effectiveForDate(int $userId, string $targetType, ?int $friendId, string $date, bool $activeOnly = true): array
    {
        $date = $this->date($date);
        $weekday = (int) (new DateTimeImmutable($date, new DateTimeZone($this->timezone)))->format('N');
        $entries = $this->schedules->listEntries($userId, $targetType, $friendId, [
            'show' => $activeOnly ? 'active' : 'all',
            'date' => $date,
        ]);
        $exceptions = $this->schedules->listExceptionsForDate($userId, $targetType, $friendId, $date);
        $exceptionsByEntry = [];
        $standalone = [];

        foreach ($exceptions as $exception) {
            $entryId = isset($exception['schedule_entry_id']) && $exception['schedule_entry_id'] !== null
                ? (int) $exception['schedule_entry_id']
                : 0;

            if ($entryId > 0) {
                $exceptionsByEntry[$entryId] = $exception;
                continue;
            }

            $standalone[] = $exception;
        }

        $effective = [];

        foreach ($entries as $entry) {
            if ((int) ($entry['weekday'] ?? 0) !== $weekday) {
                continue;
            }

            $entry['schedule_date'] = $date;
            $entry['is_effective'] = true;
            $entry['counts_as_class'] = true;
            $entry['exception_type'] = null;
            $entry['exception_id'] = null;
            $entry['exception_label'] = null;

            $exception = $exceptionsByEntry[(int) ($entry['id'] ?? 0)] ?? null;

            $effective[] = is_array($exception)
                ? $this->applyException($entry, $exception)
                : $entry;
        }

        foreach ($standalone as $exception) {
            $standaloneBlock = $this->standaloneBlock($exception, $targetType, $friendId, $date);

            if ($standaloneBlock !== null) {
                $effective[] = $standaloneBlock;
            }
        }

        $this->applyOverlapWarnings($effective);

        usort($effective, static fn (array $left, array $right): int => strcmp((string) $left['starts_at'], (string) $right['starts_at']));

        return $effective;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $exception
     * @return array<string, mixed>
     */
    private function applyException(array $entry, array $exception): array
    {
        $type = (string) ($exception['type'] ?? '');
        $entry['exception_id'] = $exception['id'] ?? null;
        $entry['exception_type'] = $type;
        $entry['exception_label'] = $exception['type_label'] ?? $this->typeLabel($type);
        $entry['exception_notes'] = $exception['notes'] ?? null;
        $entry['counts_as_class'] = !in_array($type, ['cancelled', 'absent'], true);

        if ($type === 'cancelled') {
            $entry['is_cancelled'] = true;
            return $entry;
        }

        if ($type === 'absent') {
            $entry['is_absent'] = true;
            return $entry;
        }

        if ($type !== 'modified') {
            return $entry;
        }

        foreach (['starts_at', 'ends_at', 'course_name', 'course_code', 'room', 'campus'] as $field) {
            if (isset($exception[$field]) && $exception[$field] !== null && $exception[$field] !== '') {
                $entry[$field] = $exception[$field];
            }
        }

        $entry['is_modified'] = true;
        $entry['starts_at_input'] = $this->timeInput($entry['starts_at'] ?? null);
        $entry['ends_at_input'] = $this->timeInput($entry['ends_at'] ?? null);
        $entry['effective_campus'] = $entry['campus'] ?? $entry['effective_campus'] ?? null;

        return $entry;
    }

    /**
     * @param array<string, mixed> $exception
     * @return array<string, mixed>|null
     */
    private function standaloneBlock(array $exception, string $targetType, ?int $friendId, string $date): ?array
    {
        if (($exception['starts_at'] ?? null) === null || ($exception['ends_at'] ?? null) === null || ($exception['course_name'] ?? null) === null) {
            return null;
        }

        $type = (string) ($exception['type'] ?? '');

        return [
            'id' => 'exception:' . (string) ($exception['id'] ?? ''),
            'target_type' => $targetType,
            'friend_id' => $friendId,
            'schedule_date' => $date,
            'weekday' => (int) (new DateTimeImmutable($date, new DateTimeZone($this->timezone)))->format('N'),
            'starts_at' => $exception['starts_at'],
            'ends_at' => $exception['ends_at'],
            'starts_at_input' => $this->timeInput($exception['starts_at'] ?? null),
            'ends_at_input' => $this->timeInput($exception['ends_at'] ?? null),
            'course_name' => $exception['course_name'],
            'course_code' => $exception['course_code'] ?? null,
            'room' => $exception['room'] ?? null,
            'campus' => $exception['campus'] ?? null,
            'effective_campus' => $exception['campus'] ?? null,
            'valid_from' => null,
            'valid_until' => null,
            'exception_id' => $exception['id'] ?? null,
            'exception_type' => $type,
            'exception_label' => $exception['type_label'] ?? $this->typeLabel($type),
            'exception_notes' => $exception['notes'] ?? null,
            'is_effective' => true,
            'is_standalone_exception' => true,
            'is_cancelled' => $type === 'cancelled',
            'is_absent' => $type === 'absent',
            'is_modified' => $type === 'modified',
            'counts_as_class' => !in_array($type, ['cancelled', 'absent'], true),
            'warnings' => [],
            'has_overlap' => false,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function applyOverlapWarnings(array &$blocks): void
    {
        foreach ($blocks as $index => $block) {
            if (($block['exception_type'] ?? null) !== 'modified' || empty($block['counts_as_class'])) {
                continue;
            }

            foreach ($blocks as $otherIndex => $other) {
                if ($index === $otherIndex || empty($other['counts_as_class'])) {
                    continue;
                }

                if ((string) $block['starts_at'] < (string) $other['ends_at'] && (string) $block['ends_at'] > (string) $other['starts_at']) {
                    $warnings = is_array($blocks[$index]['warnings'] ?? null) ? $blocks[$index]['warnings'] : [];
                    $warnings[] = 'Esta excepcion se superpone con otro bloque del dia.';
                    $blocks[$index]['warnings'] = array_values(array_unique($warnings));
                    $blocks[$index]['has_overlap'] = true;
                    break;
                }
            }
        }
    }

    private function date(string $date): string
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            throw new FriendValidationException('Fecha invalida.');
        }

        return $date;
    }

    private function timeInput(mixed $time): string
    {
        return is_string($time) && $time !== '' ? substr($time, 0, 5) : '';
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'cancelled' => 'Cancelada',
            'absent' => 'No asiste',
            'modified' => 'Modificada',
            default => 'Excepcion',
        };
    }
}
