<?php
declare(strict_types=1);

namespace Modules\Dashboard;

use App\Support\DateTimeHelper;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Friends\CoincidenceService;
use Modules\Friends\FriendPresenceService;
use Modules\Notifications\NotificationService;
use Modules\Organization\ReminderService;
use Modules\Organization\TaskService;
use Modules\Video\VideoDashboardSummaryService;

final class DashboardSummaryService
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $pendingTasks = null;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $pendingInboxTasks = null;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $upcomingReminders = null;

    public function __construct(
        private readonly array $appConfig,
        private readonly ?TaskService $taskService = null,
        private readonly ?int $userId = null,
        private readonly ?ReminderService $reminderService = null,
        private readonly ?NotificationService $notificationService = null,
        private readonly ?FriendPresenceService $friendPresenceService = null,
        private readonly ?CoincidenceService $coincidenceService = null,
        private readonly ?VideoDashboardSummaryService $videoDashboardSummaryService = null,
    )
    {
    }

    public function summary(): array
    {
        $timezone = $this->timezone();
        $now = DateTimeHelper::nowLocal($timezone);

        return [
            'meta' => [
                'context' => 'Resumen de hoy',
                'date_iso' => $now->format('Y-m-d'),
                'date_label' => $this->formatDate($now),
                'timezone' => $timezone,
            ],
            'sections' => [
                'today' => $this->section(
                    key: 'today',
                    title: 'Hoy',
                    eyebrow: 'Hoy',
                    description: 'Tareas, vencimientos y recordatorios de hoy.',
                    emptyState: 'Sin pendientes para mostrar.',
                    modifier: 'dashboard-card--today',
                    link: ['label' => 'Ver mas', 'url' => '/index.php?section=organization&time=today'],
                    items: $this->todayItems($now),
                ),
                'upcoming' => $this->section(
                    key: 'upcoming',
                    title: 'Proximamente',
                    eyebrow: 'Agenda',
                    description: 'Proximas tareas, vencimientos y recordatorios.',
                    emptyState: 'No hay proximos elementos con fecha.',
                    modifier: 'dashboard-card--upcoming',
                    link: ['label' => 'Ver mas', 'url' => '/index.php?section=organization&time=week'],
                    items: $this->upcomingItems($now),
                ),
                'friends' => $this->section(
                    key: 'friends',
                    title: 'Horarios',
                    eyebrow: 'Universidad',
                    description: 'Resumen segun horarios ingresados manualmente.',
                    emptyState: 'Aun no hay amigos activos con horario.',
                    modifier: 'dashboard-card--friends',
                    link: ['label' => 'Ver mas', 'url' => '/index.php?section=friends&tab=now'],
                    items: $this->friendItems($now),
                ),
                'coincidences' => $this->section(
                    key: 'coincidences',
                    title: 'Proxima coincidencia',
                    eyebrow: 'Coincidencias',
                    description: 'Actividad academica simultanea inferida desde horarios.',
                    emptyState: 'Sin coincidencias proximas.',
                    modifier: 'dashboard-card--coincidences',
                    link: ['label' => 'Ver coincidencias', 'url' => '/index.php?section=friends&tab=coincidences'],
                    items: $this->coincidenceItems($now),
                ),
                'discounts' => $this->section(
                    key: 'discounts',
                    title: 'Descuentos',
                    eyebrow: 'Beneficios',
                    description: 'Promociones compatibles con tus beneficios.',
                    emptyState: 'No hay descuentos disponibles.',
                    modifier: 'dashboard-card--discounts',
                    link: ['label' => 'Ver mas', 'url' => '/index.php?section=discounts'],
                ),
                'video' => $this->videoSection(),
                'inbox' => $this->section(
                    key: 'inbox',
                    title: 'Bandeja rapida',
                    eyebrow: 'Entrada rapida',
                    description: 'Captura pendientes sin organizarlos todavia.',
                    emptyState: 'Bandeja vacia. No tienes nada pendiente de organizar.',
                    modifier: 'dashboard-card--inbox',
                    link: ['label' => 'Abrir Bandeja', 'url' => '/index.php?section=organization&space=inbox'],
                    items: $this->inboxItems(),
                    count: $this->inboxCount(),
                ),
                'notifications' => $this->section(
                    key: 'notifications',
                    title: 'Notificaciones',
                    eyebrow: 'Avisos',
                    description: 'Ultimas notificaciones internas sin leer.',
                    emptyState: 'No hay notificaciones nuevas.',
                    modifier: 'dashboard-card--notifications',
                    link: ['label' => 'Ver todas', 'url' => '/index.php?section=notifications'],
                    items: $this->notificationItems(),
                ),
            ],
        ];
    }

    private function section(
        string $key,
        string $title,
        string $eyebrow,
        string $description,
        string $emptyState,
        string $modifier,
        ?array $link = null,
        array $items = [],
        ?int $count = null,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'eyebrow' => $eyebrow,
            'description' => $description,
            'empty_state' => $emptyState,
            'modifier' => $modifier,
            'link' => $link,
            'items' => $items,
            'count' => $count ?? count($items),
        ];
    }

    private function videoSection(): array
    {
        $videoSummary = $this->videoDashboardSummaryService !== null && $this->userId !== null
            ? $this->videoDashboardSummaryService->summary($this->userId)
            : [
                'items' => [],
                'empty_state' => 'Aun no hay actividad de video.',
                'link' => ['label' => 'Abrir editor', 'url' => '/index.php?section=video'],
            ];

        return $this->section(
            key: 'video',
            title: 'Video',
            eyebrow: 'Editor',
            description: 'Edita y exporta tus videos.',
            emptyState: (string) ($videoSummary['empty_state'] ?? 'Aun no hay actividad de video.'),
            modifier: 'dashboard-card--video',
            link: is_array($videoSummary['link'] ?? null) ? $videoSummary['link'] : ['label' => 'Abrir editor', 'url' => '/index.php?section=video'],
            items: is_array($videoSummary['items'] ?? null) ? $videoSummary['items'] : [],
        );
    }

    /**
     * @return array<int, array{label: string}>
     */
    private function inboxItems(): array
    {
        $tasks = $this->pendingInboxTasks();
        $items = [];

        foreach (array_slice($tasks, 0, 3) as $task) {
            $items[] = ['label' => (string) ($task['title'] ?? '')];
        }

        return $items;
    }

    private function inboxCount(): int
    {
        return count($this->pendingInboxTasks());
    }

    /**
     * @return array<int, array{label: string}>
     */
    private function notificationItems(): array
    {
        if ($this->notificationService === null || $this->userId === null) {
            return [];
        }

        $items = [];

        foreach ($this->notificationService->list($this->userId, ['status' => 'unread', 'limit' => 3]) as $notification) {
            $date = is_string($notification['scheduled_at_local'] ?? null)
                ? substr((string) $notification['scheduled_at_local'], 0, 16)
                : '';
            $items[] = [
                'label' => trim($date . ' ' . (string) ($notification['title'] ?? '')),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{label: string}>
     */
    private function friendItems(DateTimeImmutable $now): array
    {
        if ($this->friendPresenceService === null || $this->userId === null) {
            return [];
        }

        return $this->friendPresenceService->dashboardItems($this->userId, 3, $now);
    }

    /**
     * @return array<int, array{label: string}>
     */
    private function coincidenceItems(DateTimeImmutable $now): array
    {
        if ($this->coincidenceService === null || $this->userId === null) {
            return [];
        }

        $candidate = $this->nextCoincidence($now);

        return $candidate === null ? [] : [['label' => $candidate]];
    }

    private function nextCoincidence(DateTimeImmutable $now): ?string
    {
        $timezone = DateTimeHelper::timezone($this->timezone());
        $todayStart = $now->setTimezone($timezone)->setTime(0, 0, 0);
        $nowMinutes = ((int) $now->setTimezone($timezone)->format('H') * 60) + (int) $now->setTimezone($timezone)->format('i');
        $candidates = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $todayStart->modify('+' . $offset . ' days');
            $date = $day->format('Y-m-d');
            $individual = $this->coincidenceService->getCoincidencesForDate($this->userId, $date);

            foreach ($this->coincidenceService->groupCoincidences($individual) as $group) {
                $startMinutes = $this->timeToMinutes($group['starts_at'] ?? null);
                $endMinutes = $this->timeToMinutes($group['ends_at'] ?? null);

                if ($startMinutes === null || $endMinutes === null || ($offset === 0 && $endMinutes <= $nowMinutes)) {
                    continue;
                }

                $friends = array_map(
                    static fn (array $friend): string => (string) ($friend['name'] ?? 'Amigo'),
                    is_array($group['friends'] ?? null) ? $group['friends'] : []
                );

                if (count($friends) < 2) {
                    continue;
                }

                $campus = is_string($group['campus'] ?? null) && trim((string) $group['campus']) !== ''
                    ? ' · ' . trim((string) $group['campus']) . ' segun horario'
                    : '';
                $candidates[] = [
                    'sort' => $date . ' ' . (string) $group['starts_at'],
                    'priority' => count($friends) + 10,
                    'label' => $this->formatDashboardCoincidenceDate($day) . ' · ' . (string) $group['starts_at'] . ' - ' . (string) $group['ends_at'] . ' ' . implode(' + ', $friends) . $campus,
                ];
            }

            foreach ($individual as $item) {
                $startMinutes = $this->timeToMinutes($item['starts_at'] ?? null);
                $endMinutes = $this->timeToMinutes($item['ends_at'] ?? null);

                if ($startMinutes === null || $endMinutes === null || ($offset === 0 && $endMinutes <= $nowMinutes)) {
                    continue;
                }

                $details = [];

                if (!empty($item['same_course'])) {
                    $details[] = 'Mismo ramo' . $this->courseSuffix($item);
                }

                if (!empty($item['same_campus']) && is_string($item['user_campus'] ?? null) && trim((string) $item['user_campus']) !== '') {
                    $details[] = trim((string) $item['user_campus']) . ' segun horario';
                }

                $candidates[] = [
                    'sort' => $date . ' ' . (string) $item['starts_at'],
                    'priority' => (!empty($item['same_course']) ? 2 : 0) + (!empty($item['same_campus']) ? 1 : 0),
                    'label' => $this->formatDashboardCoincidenceDate($day) . ' · ' . (string) $item['starts_at'] . ' - ' . (string) $item['ends_at'] . ' ' . (string) ($item['friend_name'] ?? 'Amigo') . ($details === [] ? '' : ' · ' . implode(' · ', $details)),
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $left, array $right): int => strcmp((string) $left['sort'], (string) $right['sort'])
            ?: ((int) $right['priority']) <=> ((int) $left['priority']));

        return (string) $candidates[0]['label'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingInboxTasks(): array
    {
        if ($this->taskService === null || $this->userId === null) {
            return [];
        }

        if ($this->pendingInboxTasks === null) {
            $this->pendingInboxTasks = $this->taskService->list($this->userId, [
                'space_id' => 'none',
                'status' => 'pending',
            ]);
        }

        return $this->pendingInboxTasks;
    }

    /**
     * @return array<int, array{label: string}>
     */
    private function todayItems(DateTimeImmutable $now): array
    {
        $start = $now->setTime(0, 0, 0);
        $end = $start->modify('+1 day');

        return $this->dashboardItems(array_merge(
            $this->datedTaskItems($start, $end, false),
            $this->datedReminderItems($start, $end, false),
        ));
    }

    /**
     * @return array<int, array{label: string}>
     */
    private function upcomingItems(DateTimeImmutable $now): array
    {
        $start = $now->setTime(0, 0, 0)->modify('+1 day');
        $end = $start->modify('+30 days');

        return $this->dashboardItems(array_merge(
            $this->datedTaskItems($start, $end, true),
            $this->datedReminderItems($start, $end, true),
        ));
    }

    /**
     * @return array<int, array{sort: string, label: string}>
     */
    private function datedTaskItems(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, bool $futureOnly): array
    {
        if ($this->taskService === null || $this->userId === null) {
            return [];
        }

        $timezone = DateTimeHelper::timezone($this->timezone());
        $now = DateTimeHelper::nowLocal($this->timezone());
        $items = [];

        foreach ($this->pendingTasks() as $task) {
            foreach ($this->taskOccurrences($task, $timezone) as $occurrence) {
                $date = $occurrence['date'];

                if ($futureOnly && $date < $now) {
                    continue;
                }

                if ($date >= $rangeStart && $date < $rangeEnd) {
                    $items[] = [
                        'sort' => $date->format('Y-m-d H:i:s') . $occurrence['kind'],
                        'label' => $occurrence['label'],
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingTasks(): array
    {
        if ($this->taskService === null || $this->userId === null) {
            return [];
        }

        if ($this->pendingTasks === null) {
            $this->pendingTasks = $this->taskService->list($this->userId, ['status' => 'pending']);
        }

        return $this->pendingTasks;
    }

    /**
     * @return array<int, array{sort: string, label: string}>
     */
    private function datedReminderItems(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, bool $futureOnly): array
    {
        if ($this->reminderService === null || $this->userId === null) {
            return [];
        }

        $timezone = DateTimeHelper::timezone($this->timezone());
        $now = DateTimeHelper::nowLocal($this->timezone());
        $items = [];
        $reminders = $this->dashboardReminders($rangeStart, $rangeEnd);

        foreach ($reminders as $reminder) {
            $date = new DateTimeImmutable((string) ($reminder['occurrence_at_local'] ?? $reminder['remind_at_local']), $timezone);

            if ($futureOnly && $date < $now) {
                continue;
            }

            $targetTitle = is_string($reminder['target_title'] ?? null) && $reminder['target_title'] !== ''
                ? ' (' . $reminder['target_title'] . ')'
                : '';
            $items[] = [
                'sort' => $date->format('Y-m-d H:i:s') . 'reminder',
                'label' => $date->format('d/m H:i') . ' Recordatorio: ' . (string) $reminder['title'] . $targetTitle,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dashboardReminders(DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
    {
        if ($this->reminderService === null || $this->userId === null) {
            return [];
        }

        if ($this->upcomingReminders === null) {
            $timezone = DateTimeHelper::timezone($this->timezone());
            $base = DateTimeHelper::nowLocal($this->timezone())->setTimezone($timezone)->setTime(0, 0, 0);
            $this->upcomingReminders = $this->reminderService->list($this->userId, [
                'status' => 'pending',
                'remind_from' => $base->format('Y-m-d H:i:s'),
                'remind_before' => $base->modify('+31 days')->format('Y-m-d H:i:s'),
            ]);
        }

        return array_values(array_filter(
            $this->upcomingReminders,
            static function (array $reminder) use ($rangeStart, $rangeEnd): bool {
                $value = (string) ($reminder['occurrence_at_local'] ?? $reminder['remind_at_local'] ?? '');

                if ($value === '') {
                    return false;
                }

                try {
                    $date = new DateTimeImmutable($value, $rangeStart->getTimezone());
                } catch (\Throwable) {
                    return false;
                }

                return $date >= $rangeStart && $date < $rangeEnd;
            },
        ));
    }

    /**
     * @param array<int, array{sort: string, label: string}> $items
     * @return array<int, array{label: string}>
     */
    private function dashboardItems(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        return array_map(
            static fn (array $item): array => ['label' => (string) $item['label']],
            array_slice($items, 0, 5),
        );
    }

    /**
     * @param array<string, mixed> $task
     * @return array<int, array{kind: string, date: DateTimeImmutable, label: string}>
     */
    private function taskOccurrences(array $task, DateTimeZone $timezone): array
    {
        $occurrences = [];
        $title = (string) ($task['title'] ?? '');

        if (is_string($task['starts_at'] ?? null) && $task['starts_at'] !== '') {
            $localStart = DateTimeHelper::utcStorageToLocalDateTime((string) $task['starts_at'], $timezone->getName());
            $occurrences[] = [
                'kind' => 'scheduled',
                'date' => $localStart,
                'label' => $localStart->format('d/m H:i') . ' ' . $title,
            ];
        }

        if (is_string($task['due_at'] ?? null) && $task['due_at'] !== '') {
            $localDue = DateTimeHelper::utcStorageToLocalDateTime((string) $task['due_at'], $timezone->getName());
            $occurrences[] = [
                'kind' => 'due',
                'date' => $localDue,
                'label' => $localDue->format('d/m H:i') . ' Vence: ' . $title,
            ];
        }

        return $occurrences;
    }

    private function timeToMinutes(mixed $time): ?int
    {
        if (!is_string($time) || preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])(?::[0-5][0-9])?\z/', $time, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function courseSuffix(array $item): string
    {
        $block = is_array($item['user_block'] ?? null) ? $item['user_block'] : [];
        $course = trim((string) ($block['course_code'] ?? ''));

        if ($course === '') {
            $course = trim((string) ($block['course_name'] ?? ''));
        }

        return $course === '' ? '' : ' · ' . $course;
    }

    private function formatDashboardCoincidenceDate(DateTimeImmutable $date): string
    {
        $weekdays = [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miercoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sabado',
            7 => 'Domingo',
        ];

        return $weekdays[(int) $date->format('N')];
    }

    private function timezone(): string
    {
        $timezone = $this->appConfig['timezone'] ?? DateTimeHelper::DEFAULT_TIMEZONE;

        return is_string($timezone) && $timezone !== '' ? $timezone : DateTimeHelper::DEFAULT_TIMEZONE;
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        $months = [
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre',
        ];
        $weekdays = [
            1 => 'lunes',
            2 => 'martes',
            3 => 'miercoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sabado',
            7 => 'domingo',
        ];

        return sprintf(
            '%s %s de %s de %s',
            $weekdays[(int) $date->format('N')],
            $date->format('j'),
            $months[(int) $date->format('n')],
            $date->format('Y'),
        );
    }
}
