<?php
declare(strict_types=1);

namespace Modules\Organization;

use App\Support\DateTimeHelper;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;

final class CalendarService
{
    public function __construct(
        private readonly CalendarRepository $calendar,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
        private readonly ?LabelService $labels = null,
        private readonly ?DateTimeImmutable $now = null,
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function view(int $userId, string $view, ?string $date = null): array
    {
        $view = in_array($view, ['month', 'week'], true) ? $view : 'month';
        $timezone = DateTimeHelper::timezone($this->timezone);
        $anchor = $this->anchor($date, $timezone);

        if ($view === 'week') {
            $rangeStart = $anchor->modify('monday this week')->setTime(0, 0, 0);
            $rangeEnd = $rangeStart->modify('+7 days');
            $title = $rangeStart->format('d/m/Y') . ' - ' . $rangeEnd->modify('-1 day')->format('d/m/Y');
        } else {
            $rangeStart = $anchor->modify('first day of this month')->setTime(0, 0, 0);
            $rangeEnd = $rangeStart->modify('+1 month');
            $title = $this->monthName((int) $rangeStart->format('n')) . ' ' . $rangeStart->format('Y');
        }

        $today = ($this->now ?? DateTimeHelper::nowLocal($this->timezone))
            ->setTimezone($timezone)
            ->format('Y-m-d');
        $selected = $anchor->format('Y-m-d');
        $days = $this->days($rangeStart, $rangeEnd, $today, $selected);
        $itemsByDate = $this->itemsByDate($userId, $rangeStart, $rangeEnd, $timezone);

        return [
            'view' => $view,
            'title' => $title,
            'anchor_date' => $anchor->format('Y-m-d'),
            'today_date' => $today,
            'previous_date' => ($view === 'week' ? $rangeStart->modify('-7 days') : $rangeStart->modify('-1 month'))->format('Y-m-d'),
            'next_date' => ($view === 'week' ? $rangeStart->modify('+7 days') : $rangeStart->modify('+1 month'))->format('Y-m-d'),
            'range_start' => $rangeStart->format('Y-m-d'),
            'range_end' => $rangeEnd->modify('-1 day')->format('Y-m-d'),
            'days' => $days,
            'items_by_date' => $itemsByDate,
        ];
    }

    private function anchor(?string $date, DateTimeZone $timezone): DateTimeImmutable
    {
        if (is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);

            if ($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date) {
                return $parsed;
            }
        }

        return new DateTimeImmutable('today', $timezone);
    }

    /**
     * @return array<int, array{date: string, day: string, weekday: string, in_current_month: bool, is_today: bool, is_selected: bool}>
     */
    private function days(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, string $today, string $selected): array
    {
        $periodStart = $rangeStart;
        $periodEnd = $rangeEnd;
        $currentMonth = $rangeStart->format('m');

        if ($rangeEnd->diff($rangeStart)->days !== 7) {
            $periodStart = $rangeStart->modify('monday this week');
            $periodEnd = $rangeEnd->modify('-1 day')->modify('sunday this week')->modify('+1 day');
        }

        $days = [];
        $period = new DatePeriod($periodStart, new DateInterval('P1D'), $periodEnd);

        foreach ($period as $day) {
            $days[] = [
                'date' => $day->format('Y-m-d'),
                'day' => $day->format('j'),
                'weekday' => $this->weekdayName((int) $day->format('N')),
                'in_current_month' => $day->format('m') === $currentMonth,
                'is_today' => $day->format('Y-m-d') === $today,
                'is_selected' => $day->format('Y-m-d') === $selected,
            ];
        }

        return $days;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function itemsByDate(int $userId, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, DateTimeZone $timezone): array
    {
        $utc = DateTimeHelper::utcTimezone();
        $tasks = $this->calendar->tasksInRange(
            $userId,
            $rangeStart->setTimezone($utc)->format('Y-m-d H:i:s'),
            $rangeEnd->setTimezone($utc)->format('Y-m-d H:i:s'),
        );
        $projects = $this->calendar->projectsInRange(
            $userId,
            $rangeStart->format('Y-m-d'),
            $rangeEnd->modify('-1 day')->format('Y-m-d'),
        );

        if ($this->labels !== null) {
            $tasks = $this->labels->attachLabelsToEntities($userId, 'task', $tasks);
            $projects = $this->labels->attachLabelsToEntities($userId, 'project', $projects);
        }

        $itemsByDate = [];

        foreach ($tasks as $task) {
            if (($task['starts_at'] ?? null) !== null) {
                $this->addTaskRange($itemsByDate, $task, $rangeStart, $rangeEnd, $timezone);
            }

            if (($task['due_at'] ?? null) !== null) {
                $this->addTaskDue($itemsByDate, $task, $rangeStart, $rangeEnd, $timezone);
            }
        }

        foreach ($projects as $project) {
            $this->addProjectRange($itemsByDate, $project, $rangeStart, $rangeEnd);
        }

        foreach ($itemsByDate as &$items) {
            usort($items, static function (array $left, array $right): int {
                return [$left['sort'], $left['title']] <=> [$right['sort'], $right['title']];
            });
        }
        unset($items);

        return $itemsByDate;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $itemsByDate
     * @param array<string, mixed> $task
     */
    private function addTaskRange(array &$itemsByDate, array $task, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, DateTimeZone $timezone): void
    {
        $startsAt = $this->utcDateTime((string) $task['starts_at'])->setTimezone($timezone);
        $endsAt = ($task['ends_at'] ?? null) !== null
            ? $this->utcDateTime((string) $task['ends_at'])->setTimezone($timezone)
            : $startsAt;
        $dayStart = max($startsAt->setTime(0, 0, 0), $rangeStart);
        $dayEnd = min($endsAt->setTime(0, 0, 0)->modify('+1 day'), $rangeEnd);
        $period = new DatePeriod($dayStart, new DateInterval('P1D'), $dayEnd);

        foreach ($period as $day) {
            $date = $day->format('Y-m-d');
            $itemsByDate[$date][] = [
                'type' => 'task-scheduled',
                'entity_type' => 'task',
                'entity_id' => (int) $task['id'],
                'title' => (string) $task['title'],
                'label' => $this->taskTimeLabel($startsAt, $endsAt, $date) . ' ' . (string) $task['title'],
                'label_summary' => $this->labelSummary($task['labels'] ?? []),
                'indicator_color' => $this->indicatorColor($task['labels'] ?? [], '#2DD4BF'),
                'sort' => $startsAt->format('H:i'),
                'url' => $this->taskUrl($task),
            ];
        }
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $itemsByDate
     * @param array<string, mixed> $task
     */
    private function addTaskDue(array &$itemsByDate, array $task, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, DateTimeZone $timezone): void
    {
        $dueAt = $this->utcDateTime((string) $task['due_at'])->setTimezone($timezone);

        if ($dueAt < $rangeStart || $dueAt >= $rangeEnd) {
            return;
        }

        $date = $dueAt->format('Y-m-d');
        $itemsByDate[$date][] = [
            'type' => 'task-due',
            'entity_type' => 'task',
            'entity_id' => (int) $task['id'],
            'title' => (string) $task['title'],
            'label' => $dueAt->format('H:i') . ' Vence: ' . (string) $task['title'],
            'label_summary' => $this->labelSummary($task['labels'] ?? []),
            'indicator_color' => $this->indicatorColor($task['labels'] ?? [], '#FB7185'),
            'sort' => $dueAt->format('H:i') . 'z',
            'url' => $this->taskUrl($task),
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $itemsByDate
     * @param array<string, mixed> $project
     */
    private function addProjectRange(array &$itemsByDate, array $project, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): void
    {
        $startsOn = (string) ($project['starts_on'] ?? $project['due_on']);
        $dueOn = (string) ($project['due_on'] ?? $project['starts_on']);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn) ?: $rangeStart;
        $end = (DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn) ?: $start)->modify('+1 day');
        $period = new DatePeriod(max($start, $rangeStart), new DateInterval('P1D'), min($end, $rangeEnd));
        $label = 'Proyecto ' . (string) $project['title'];

        foreach ($period as $day) {
            $date = $day->format('Y-m-d');
            $itemsByDate[$date][] = [
                'type' => 'project',
                'entity_type' => 'project',
                'entity_id' => (int) $project['id'],
                'title' => (string) $project['title'],
                'label' => $label,
                'label_summary' => $this->labelSummary($project['labels'] ?? []),
                'indicator_color' => $this->indicatorColor($project['labels'] ?? [], '#7AB7FF'),
                'sort' => '99:project',
                'url' => '/index.php?section=organization&tab=projects&project=' . rawurlencode((string) $project['id']),
            ];
        }
    }

    /**
     * @param array<string, mixed> $task
     */
    private function taskUrl(array $task): string
    {
        $taskId = rawurlencode((string) $task['id']);

        if (($task['project_id'] ?? null) !== null) {
            return '/index.php?section=organization&tab=projects&project=' . rawurlencode((string) $task['project_id']) . '&edit_task=' . $taskId;
        }

        return '/index.php?section=organization&tab=tasks&status=all&edit_task=' . $taskId;
    }

    private function taskTimeLabel(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, string $date): string
    {
        if ($startsAt->format('Y-m-d') !== $date || $endsAt->format('Y-m-d') !== $date) {
            return $startsAt->format('d/m H:i') . '-' . $endsAt->format('d/m H:i');
        }

        if ($startsAt == $endsAt) {
            return $startsAt->format('H:i');
        }

        return $startsAt->format('H:i') . '-' . $endsAt->format('H:i');
    }

    private function utcDateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, DateTimeHelper::utcTimezone());
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ][$month] ?? '';
    }

    /**
     * @param mixed $labels
     */
    private function labelSummary(mixed $labels): string
    {
        if (!is_array($labels) || $labels === []) {
            return '';
        }

        $names = array_values(array_filter(array_map(
            static fn (mixed $label): string => is_array($label) ? (string) ($label['name'] ?? '') : '',
            $labels,
        )));

        if ($names === []) {
            return '';
        }

        $visible = array_slice($names, 0, 2);
        $remaining = count($names) - count($visible);

        return implode(' ', $visible) . ($remaining > 0 ? ' +' . $remaining : '');
    }

    private function indicatorColor(mixed $labels, string $fallback): string
    {
        if (!is_array($labels)) {
            return $fallback;
        }

        foreach ($labels as $label) {
            $color = is_array($label) ? strtoupper((string) ($label['color'] ?? '')) : '';

            if (preg_match('/\A#[0-9A-F]{6}\z/', $color) === 1) {
                return $color;
            }
        }

        return $fallback;
    }

    private function weekdayName(int $weekday): string
    {
        return [
            1 => 'Lun',
            2 => 'Mar',
            3 => 'Mie',
            4 => 'Jue',
            5 => 'Vie',
            6 => 'Sab',
            7 => 'Dom',
        ][$weekday] ?? '';
    }
}
