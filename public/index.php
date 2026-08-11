<?php
declare(strict_types=1);

use App\Http\RequireAuth;
use App\Http\SecurityHeaders;
use App\Http\Session;
use App\Support\Navigation;
use App\Support\DateTimeHelper;
use App\Support\View;
use App\Database\Connection;
use Modules\Dashboard\DashboardSummaryService;
use Modules\Friends\CoincidenceService;
use Modules\Friends\CoincidenceTaskPrefillService;
use Modules\Friends\FriendRepository;
use Modules\Friends\FriendPresenceService;
use Modules\Friends\FriendScheduleRepository;
use Modules\Friends\FriendScheduleResolver;
use Modules\Friends\FriendScheduleService;
use Modules\Friends\FriendService;
use Modules\Friends\FriendValidationException;
use Modules\Notifications\NotificationRepository;
use Modules\Notifications\NotificationService;
use Modules\Organization\CalendarRepository;
use Modules\Organization\CalendarService;
use Modules\Organization\NoteRepository;
use Modules\Organization\NoteService;
use Modules\Organization\ProjectRepository;
use Modules\Organization\ProjectService;
use Modules\Organization\ReminderRepository;
use Modules\Organization\ReminderService;
use Modules\Organization\SpaceRepository;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;
use Modules\Organization\TaskValidationException;
use Modules\Video\VideoCutPointRepository;
use Modules\Video\VideoEditSegmentRepository;
use Modules\Video\TranscriptionSourceResolver;
use Modules\Video\VideoEditorService;
use Modules\Video\VideoExportJobRepository;
use Modules\Video\VideoExportService;
use Modules\Video\VideoRepository;
use Modules\Video\VideoService;
use Modules\Video\VideoStorage;
use Modules\Video\VideoTranscriptionRepository;
use Modules\Video\VideoTranscriptionService;
use Modules\Video\VideoValidationException;
use Modules\Video\WhisperService;

/**
 * @param array<string, mixed> $request
 * @param array<int, array<string, mixed>> $spaces
 * @param array<string, mixed> $appConfig
 * @return array{ui: array<string, string>, service: array<string, string>, project_service: array<string, string>, note_service: array<string, string>, reminder_service: array<string, string>, calendar: array<string, string>}
 */
function organizationFiltersFromRequest(array $request, array $spaces, array $appConfig): array
{
    $tab = is_string($request['tab'] ?? null) ? $request['tab'] : 'tasks';
    $legacyType = is_string($request['type'] ?? null) ? $request['type'] : '';
    $status = is_string($request['status'] ?? null) ? $request['status'] : 'pending';
    $space = is_string($request['space'] ?? null) ? $request['space'] : 'all';
    $priority = is_string($request['priority'] ?? null) ? $request['priority'] : 'all';
    $time = is_string($request['time'] ?? null) ? $request['time'] : 'all';
    $spaceIdsBySlug = [];

    foreach ($spaces as $spaceRow) {
        if (isset($spaceRow['slug'], $spaceRow['id'])) {
            $spaceIdsBySlug[(string) $spaceRow['slug']] = (string) $spaceRow['id'];
        }
    }

    if ($legacyType === 'projects') {
        $tab = 'projects';
    }

    if (!in_array($tab, ['tasks', 'projects', 'notes', 'reminders', 'calendar'], true)) {
        $tab = 'tasks';
    }

    if (!is_string($request['status'] ?? null)) {
        $status = $tab === 'projects' ? 'active' : 'pending';
    }

    if ($tab === 'projects') {
        if ($status === 'pending') {
            $status = 'active';
        }

        if (!in_array($status, ['active', 'completed', 'archived', 'all'], true)) {
            $status = 'active';
        }
    } elseif ($tab === 'reminders') {
        if (!in_array($status, ['pending', 'done', 'all'], true)) {
            $status = 'pending';
        }
    } elseif ($tab === 'notes' || $tab === 'calendar') {
        $status = 'all';
    } elseif (!in_array($status, ['pending', 'completed', 'all'], true)) {
        $status = 'pending';
    }

    if ($space !== 'all' && $space !== 'inbox' && !array_key_exists($space, $spaceIdsBySlug)) {
        $space = 'all';
    }

    if (!in_array($priority, ['low', 'normal', 'high', 'all'], true)) {
        $priority = 'all';
    }

    if (!in_array($time, ['all', 'today', 'week', 'overdue'], true)) {
        $time = 'all';
    }

    $service = [
        'project_id' => 'none',
        'parent_task_id' => 'none',
    ];
    $projectService = [];
    $noteService = [];
    $reminderService = [];
    $calendar = [
        'view' => is_string($request['view'] ?? null) ? (string) $request['view'] : 'month',
        'date' => is_string($request['date'] ?? null) ? (string) $request['date'] : '',
    ];

    if (!in_array($calendar['view'], ['month', 'week'], true)) {
        $calendar['view'] = 'month';
    }

    if ($status !== 'all') {
        $service['status'] = $status;
        $projectService['status'] = $status;
    }

    if ($space === 'inbox') {
        $service['space_id'] = 'none';
        $projectService['space_id'] = 'none';
        $noteService['space_id'] = 'none';
    } elseif ($space !== 'all') {
        $service['space_id'] = $spaceIdsBySlug[$space];
        $projectService['space_id'] = $spaceIdsBySlug[$space];
        $noteService['space_id'] = $spaceIdsBySlug[$space];
    }

    if ($tab === 'projects' || $tab === 'notes' || $tab === 'reminders' || $tab === 'calendar') {
        $priority = 'all';
        $time = 'all';
    }

    if ($priority !== 'all') {
        $service['priority'] = $priority;
    }

    $timeFilters = [];

    if ($time !== 'all') {
        $service['status'] = 'pending';
        $projectService['status'] = 'pending';
        $status = 'pending';
        $timeFilters = organizationTimeFilters($time, $appConfig);
        $service = array_merge($service, $timeFilters);
        $projectService = array_merge($projectService, $timeFilters);
    }

    if ($tab === 'reminders') {
        $reminderService['status'] = $status;
    }

    return [
        'ui' => [
            'tab' => $tab,
            'status' => $status,
            'space' => $space,
            'priority' => $priority,
            'time' => $time,
            'due_from' => $timeFilters['due_from'] ?? '',
            'due_to' => $timeFilters['due_to'] ?? '',
            'due_before' => $timeFilters['due_before'] ?? '',
            'view' => $calendar['view'],
            'date' => $calendar['date'],
        ],
        'service' => $service,
        'project_service' => $projectService,
        'note_service' => $noteService,
        'reminder_service' => $reminderService,
        'calendar' => $calendar,
    ];
}

/**
 * @param array<string, mixed> $appConfig
 * @return array<string, string>
 */
function organizationTimeFilters(string $time, array $appConfig): array
{
    $timezoneName = is_string($appConfig['timezone'] ?? null) && $appConfig['timezone'] !== ''
        ? $appConfig['timezone']
        : DateTimeHelper::DEFAULT_TIMEZONE;
    $now = DateTimeHelper::nowLocal($timezoneName);

    if ($time === 'today') {
        $start = $now->setTime(0, 0, 0);
        $end = $start->modify('+1 day');

        return [
            'due_from' => $start->format('Y-m-d H:i:s'),
            'due_before' => $end->format('Y-m-d H:i:s'),
        ];
    }

    if ($time === 'week') {
        $start = $now->setTime(0, 0, 0);
        $daysUntilSunday = 7 - (int) $now->format('N');
        $end = $start->modify('+' . ($daysUntilSunday + 1) . ' days');

        return [
            'due_from' => $start->format('Y-m-d H:i:s'),
            'due_before' => $end->format('Y-m-d H:i:s'),
        ];
    }

    if ($time === 'overdue') {
        return [
            'due_before' => $now->format('Y-m-d H:i:s'),
        ];
    }

    return [];
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';

SecurityHeaders::send();
RequireAuth::ensure($config['app']['session']);

$user = Session::user();
$username = is_array($user) ? (string) $user['username'] : '';
$userId = is_array($user) ? (int) ($user['user_id'] ?? 0) : 0;
$section = is_string($_GET['section'] ?? null) ? $_GET['section'] : null;
$page = Navigation::resolve($section);
$activeSection = $page['key'] ?? '';
$navigationGroups = Navigation::groups();
$dashboardSummary = null;
$notificationSummary = [
    'unread_count' => 0,
    'recent' => [],
];
$notificationsPage = [
    'status' => 'all',
    'items' => [],
    'unread_count' => 0,
];
$organizationTasks = null;
$organizationSpaces = [];
$taskFilters = [];
$taskFilterError = null;
$taskPageMode = 'tasks';
$organizationProjects = [];
$selectedProject = null;
$selectedProjectTasks = [];
$organizationNotes = [];
$organizationCalendar = null;
$organizationReminders = [];
$organizationTaskPrefill = null;
$friends = [];
$activeFriends = [];
$friendScheduleEntries = [];
$friendNowItems = [];
$friendTodayItems = [];
$friendWeek = null;
$friendCoincidences = null;
$videoFiles = [];
$videoConfig = [];
$selectedVideo = null;
$videoNotFound = false;
$videoMode = 'list';
$videoCutPoints = [];
$videoSegments = [];
$videoSegmentSummary = null;
$videoExportJobs = [];
$videoTranscriptions = [];
$videoEditorError = null;
$friendFilters = [
    'status' => 'active',
    'tab' => 'now',
    'target_type' => 'user',
    'friend_id' => '',
    'show' => 'active',
    'week' => '',
    'coincidence_date' => '',
    'coincidence_friend_id' => '',
    'same_campus_only' => '0',
];
$notificationService = new NotificationService(
    new NotificationRepository(Connection::get()),
    (string) ($config['app']['timezone'] ?? 'America/Santiago'),
);
$notificationSummary = [
    'unread_count' => $notificationService->unreadCount($userId),
    'recent' => $notificationService->list($userId, ['limit' => 5]),
];

if (is_array($page) && $page['key'] === 'home') {
    $pdo = Connection::get();
    $taskService = new TaskService(new TaskRepository($pdo));
    $reminderService = new ReminderService(new ReminderRepository($pdo), (string) ($config['app']['timezone'] ?? 'America/Santiago'));
    $scheduleService = new FriendScheduleService(new FriendScheduleRepository($pdo));
    $scheduleResolver = new FriendScheduleResolver($scheduleService, (string) ($config['app']['timezone'] ?? 'America/Santiago'));
    $friendPresenceService = new FriendPresenceService(
        new FriendRepository($pdo),
        $scheduleService,
        (string) ($config['app']['timezone'] ?? 'America/Santiago'),
        $scheduleResolver,
    );
    $coincidenceService = new CoincidenceService(new FriendRepository($pdo), $scheduleResolver);
    $dashboardSummary = (new DashboardSummaryService($config['app'], $taskService, $userId, $reminderService, $notificationService, $friendPresenceService, $coincidenceService))->summary();
}

if (is_array($page) && $page['key'] === 'notifications') {
    $notificationStatus = is_string($_GET['status'] ?? null) ? (string) $_GET['status'] : 'all';

    if (!in_array($notificationStatus, ['all', 'unread', 'read'], true)) {
        $notificationStatus = 'all';
    }

    $notificationsPage = [
        'status' => $notificationStatus,
        'items' => $notificationService->list($userId, ['status' => $notificationStatus, 'limit' => 50]),
        'unread_count' => $notificationSummary['unread_count'],
    ];
}

if (is_array($page) && $page['key'] === 'organization') {
    $pdo = Connection::get();
    $taskService = new TaskService(new TaskRepository($pdo));
    $projectService = new ProjectService(new ProjectRepository($pdo));
    $noteService = new NoteService(new NoteRepository($pdo));
    $reminderService = new ReminderService(new ReminderRepository($pdo), (string) ($config['app']['timezone'] ?? 'America/Santiago'));
    $calendarService = new CalendarService(new CalendarRepository($pdo), (string) ($config['app']['timezone'] ?? 'America/Santiago'));
    $spaceRepository = new SpaceRepository($pdo);
    $organizationSpaces = $spaceRepository->listForUser($userId);
    $taskPageMode = 'organization';
    $taskFilters = organizationFiltersFromRequest($_GET, $organizationSpaces, $config['app']);

    try {
        if (($_GET['from'] ?? '') === 'coincidence') {
            $scheduleService = new FriendScheduleService(new FriendScheduleRepository($pdo));
            $scheduleResolver = new FriendScheduleResolver($scheduleService, (string) ($config['app']['timezone'] ?? 'America/Santiago'));
            $prefillService = new CoincidenceTaskPrefillService(
                new CoincidenceService(new FriendRepository($pdo), $scheduleResolver),
                $spaceRepository,
            );
            $organizationTaskPrefill = $prefillService->fromQuery($userId, $_GET);
            $taskFilters['ui']['tab'] = 'tasks';
        }

        $tabFilter = $taskFilters['ui']['tab'] ?? 'tasks';
        $projectId = isset($_GET['project']) && is_string($_GET['project']) && preg_match('/\A[1-9][0-9]*\z/', $_GET['project']) === 1
            ? (int) $_GET['project']
            : null;

        if ($projectId !== null) {
            $selectedProject = $projectService->get($userId, $projectId);
            $selectedProjectTasks = $selectedProject === null ? [] : $taskService->list($userId, [
                'project_id' => (string) $projectId,
            ]);
            $organizationTasks = [];
            $organizationProjects = [];
        } else {
            $organizationTasks = $tabFilter === 'tasks' ? $taskService->list($userId, $taskFilters['service']) : [];
            $organizationProjects = $tabFilter === 'projects' ? $projectService->list($userId, $taskFilters['project_service']) : [];
            $organizationNotes = $tabFilter === 'notes' ? $noteService->list($userId, $taskFilters['note_service']) : [];
            $organizationReminders = $tabFilter === 'reminders' ? $reminderService->list($userId, $taskFilters['reminder_service']) : [];
            $organizationCalendar = $tabFilter === 'calendar'
                ? $calendarService->view($userId, $taskFilters['calendar']['view'] ?? 'month', $taskFilters['calendar']['date'] ?? null)
                : null;
        }
    } catch (FriendValidationException) {
        http_response_code(422);
        $organizationTasks = [];
        $organizationProjects = [];
        $organizationNotes = [];
        $organizationReminders = [];
        $organizationCalendar = null;
        $taskFilterError = 'No se pudo preparar la tarea desde esa coincidencia.';
    } catch (TaskValidationException) {
        $organizationTasks = [];
        $organizationProjects = [];
        $organizationNotes = [];
        $organizationReminders = [];
        $organizationCalendar = null;
        $taskFilterError = 'Los filtros seleccionados no son validos.';
    }
}

if (is_array($page) && $page['key'] === 'friends') {
    $pdo = Connection::get();
    $friendService = new FriendService(new FriendRepository($pdo));
    $scheduleService = new FriendScheduleService(new FriendScheduleRepository($pdo));
    $friendStatus = is_string($_GET['status'] ?? null) ? (string) $_GET['status'] : 'active';
    $friendTab = is_string($_GET['tab'] ?? null) ? (string) $_GET['tab'] : 'now';
    $scheduleShow = is_string($_GET['show'] ?? null) ? (string) $_GET['show'] : 'active';
    $scheduleTarget = is_string($_GET['target'] ?? null) ? (string) $_GET['target'] : 'user';
    $scheduleWeek = is_string($_GET['week'] ?? null) ? (string) $_GET['week'] : '';
    $coincidenceDate = is_string($_GET['date'] ?? null) ? (string) $_GET['date'] : '';
    $coincidenceFriendId = is_string($_GET['friend_id'] ?? null) ? (string) $_GET['friend_id'] : '';
    $sameCampusOnly = isset($_GET['same_campus_only']) && (string) $_GET['same_campus_only'] === '1';

    if (!in_array($friendStatus, ['active', 'all', 'inactive'], true)) {
        $friendStatus = 'active';
    }

    if (!in_array($friendTab, ['now', 'today', 'week', 'coincidences', 'friends', 'schedules'], true)) {
        $friendTab = 'now';
    }

    if (!in_array($scheduleShow, ['active', 'all'], true)) {
        $scheduleShow = 'active';
    }

    $targetType = 'user';
    $selectedFriendId = null;

    if (preg_match('/\Afriend:([1-9][0-9]*)\z/', $scheduleTarget, $matches) === 1) {
        $targetType = 'friend';
        $selectedFriendId = (int) $matches[1];
    } else {
        $scheduleTarget = 'user';
    }

    $friendFilters = [
        'status' => $friendStatus,
        'tab' => $friendTab,
        'target_type' => $targetType,
        'friend_id' => $selectedFriendId === null ? '' : (string) $selectedFriendId,
        'target' => $scheduleTarget,
        'show' => $scheduleShow,
        'week' => $scheduleWeek,
        'coincidence_date' => $coincidenceDate,
        'coincidence_friend_id' => $coincidenceFriendId,
        'same_campus_only' => $sameCampusOnly ? '1' : '0',
    ];
    $friends = $friendService->list($userId, ['status' => $friendStatus]);
    $activeFriends = $friendService->list($userId, ['status' => 'active']);
    $scheduleResolver = new FriendScheduleResolver($scheduleService, (string) ($config['app']['timezone'] ?? 'America/Santiago'));
    $presenceService = new FriendPresenceService(new FriendRepository($pdo), $scheduleService, (string) ($config['app']['timezone'] ?? 'America/Santiago'), $scheduleResolver);
    $friendNowItems = $friendTab === 'now' ? $presenceService->now($userId) : [];
    $friendTodayItems = $friendTab === 'today' ? $presenceService->today($userId) : [];

    try {
        if ($friendTab === 'coincidences') {
            $timezone = new DateTimeZone((string) ($config['app']['timezone'] ?? 'America/Santiago'));
            $todayDate = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');

            if ($coincidenceDate === '') {
                $coincidenceDate = $todayDate;
            }

            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $coincidenceDate) !== 1) {
                http_response_code(422);
                throw new FriendValidationException('Fecha invalida.');
            }

            $dateParts = array_map('intval', explode('-', $coincidenceDate));

            if (!checkdate($dateParts[1], $dateParts[2], $dateParts[0])) {
                http_response_code(422);
                throw new FriendValidationException('Fecha invalida.');
            }

            $selectedCoincidenceFriendIds = null;

            if ($coincidenceFriendId !== '') {
                if (preg_match('/\A[1-9][0-9]*\z/', $coincidenceFriendId) !== 1) {
                    http_response_code(422);
                    throw new FriendValidationException('Amigo invalido.');
                }

                $candidateFriendId = (int) $coincidenceFriendId;
                $ownedActiveFriend = false;

                foreach ($activeFriends as $friend) {
                    if ((int) ($friend['id'] ?? 0) === $candidateFriendId) {
                        $ownedActiveFriend = true;
                        break;
                    }
                }

                if (!$ownedActiveFriend) {
                    http_response_code(422);
                    throw new FriendValidationException('Amigo invalido.');
                }

                $selectedCoincidenceFriendIds = [$candidateFriendId];
            }

            $coincidenceService = new CoincidenceService(new FriendRepository($pdo), $scheduleResolver);
            $ownEffective = $scheduleResolver->effectiveForDate($userId, 'user', null, $coincidenceDate, true);
            $individual = $coincidenceService->getCoincidencesForDate($userId, $coincidenceDate, $selectedCoincidenceFriendIds);
            $groups = $coincidenceService->getGroupCoincidencesForDate($userId, $coincidenceDate, $selectedCoincidenceFriendIds);

            if ($sameCampusOnly) {
                $individual = array_values(array_filter($individual, static fn (array $item): bool => ($item['same_campus'] ?? false) === true));
                $groups = array_values(array_filter($groups, static fn (array $item): bool => ($item['same_campus'] ?? false) === true));
            }

            $now = new DateTimeImmutable('now', $timezone);
            $nextCoincidence = null;

            if ($coincidenceDate === $todayDate) {
                $nowMinutes = ((int) $now->format('H') * 60) + (int) $now->format('i');

                foreach ($individual as $item) {
                    $startParts = array_map('intval', explode(':', (string) $item['starts_at']));
                    $endParts = array_map('intval', explode(':', (string) $item['ends_at']));
                    $startMinutes = ($startParts[0] * 60) + $startParts[1];
                    $endMinutes = ($endParts[0] * 60) + $endParts[1];

                    if ($endMinutes <= $nowMinutes) {
                        continue;
                    }

                    $nextCoincidence = array_merge($item, [
                        'is_now' => $startMinutes <= $nowMinutes && $endMinutes > $nowMinutes,
                        'minutes_until' => max(0, $startMinutes - $nowMinutes),
                    ]);
                    break;
                }
            }

            foreach ($individual as &$item) {
                $endParts = array_map('intval', explode(':', (string) $item['ends_at']));
                $endMinutes = ($endParts[0] * 60) + $endParts[1];
                $item['is_finished'] = $coincidenceDate === $todayDate && $endMinutes <= (((int) $now->format('H') * 60) + (int) $now->format('i'));
            }
            unset($item);

            $friendNamesWithCoincidences = [];
            foreach ($individual as $item) {
                $friendNamesWithCoincidences[(int) ($item['friend_id'] ?? 0)] = true;
            }

            $friendCoincidences = [
                'date' => $coincidenceDate,
                'date_label' => (new DateTimeImmutable($coincidenceDate, $timezone))->format('d/m/Y'),
                'previous' => (new DateTimeImmutable($coincidenceDate, $timezone))->modify('-1 day')->format('Y-m-d'),
                'next' => (new DateTimeImmutable($coincidenceDate, $timezone))->modify('+1 day')->format('Y-m-d'),
                'today' => $todayDate,
                'same_campus_only' => $sameCampusOnly,
                'friend_id' => $coincidenceFriendId,
                'has_own_schedule' => $ownEffective !== [],
                'has_active_friends' => $activeFriends !== [],
                'individual' => $individual,
                'groups' => $groups,
                'next_item' => $nextCoincidence,
                'friend_count' => count($friendNamesWithCoincidences),
                'error' => null,
            ];
            $friendFilters['coincidence_date'] = $coincidenceDate;
            $friendFilters['coincidence_friend_id'] = $coincidenceFriendId;
        }

        if ($friendTab === 'week') {
            $timezone = new DateTimeZone((string) ($config['app']['timezone'] ?? 'America/Santiago'));
            $weekDate = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $scheduleWeek) === 1
                ? new DateTimeImmutable($scheduleWeek, $timezone)
                : new DateTimeImmutable('now', $timezone);
            $weekStart = $weekDate->modify('-' . ((int) $weekDate->format('N') - 1) . ' days');
            $days = [];

            for ($index = 0; $index < 7; $index++) {
                $day = $weekStart->modify('+' . $index . ' days');
                $date = $day->format('Y-m-d');
                $days[] = [
                    'date' => $date,
                    'weekday' => (int) $day->format('N'),
                    'label' => $day->format('d/m'),
                    'blocks' => $scheduleResolver->effectiveForDate($userId, $targetType, $selectedFriendId, $date, true),
                ];
            }

            $friendWeek = [
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekStart->modify('+6 days')->format('Y-m-d'),
                'label' => $weekStart->format('d/m/Y') . ' - ' . $weekStart->modify('+6 days')->format('d/m/Y'),
                'previous' => $weekStart->modify('-7 days')->format('Y-m-d'),
                'next' => $weekStart->modify('+7 days')->format('Y-m-d'),
                'today' => (new DateTimeImmutable('now', $timezone))->format('Y-m-d'),
                'days' => $days,
            ];
            $friendFilters['week'] = $weekStart->format('Y-m-d');
        }

        $friendScheduleEntries = $friendTab === 'schedules'
            ? $scheduleService->listEntries($userId, $targetType, $selectedFriendId, ['show' => $scheduleShow])
            : [];
    } catch (FriendValidationException) {
        if ($friendTab === 'coincidences') {
            $friendCoincidences = [
                'date' => $coincidenceDate,
                'date_label' => '',
                'previous' => '',
                'next' => '',
                'today' => '',
                'same_campus_only' => $sameCampusOnly,
                'friend_id' => $coincidenceFriendId,
                'has_own_schedule' => true,
                'has_active_friends' => true,
                'individual' => [],
                'groups' => [],
                'next_item' => null,
                'friend_count' => 0,
                'error' => 'Parametros invalidos para calcular coincidencias.',
            ];
        }

        $friendFilters['target_type'] = 'user';
        $friendFilters['friend_id'] = '';
        $friendFilters['target'] = 'user';
        $targetType = 'user';
        $selectedFriendId = null;
        $friendScheduleEntries = $friendTab === 'schedules'
            ? $scheduleService->listEntries($userId, 'user', null, ['show' => $scheduleShow])
            : [];
    }
}

if (is_array($page) && $page['key'] === 'video-editor') {
    $videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
    $pdo = Connection::get();
    $videoRepository = new VideoRepository($pdo);
    $videoService = new VideoService($videoRepository, $videoConfig);
    $videoId = is_string($_GET['id'] ?? null) && preg_match('/\A[1-9][0-9]*\z/', (string) $_GET['id']) === 1
        ? (int) $_GET['id']
        : null;

    if ($videoId === null) {
        $videoMode = 'list';
        $videoFiles = $videoService->list($userId);
    } else {
        $videoMode = (string) ($_GET['editor'] ?? '') === '1' ? 'editor' : 'detail';
        $selectedVideo = $videoService->get($userId, $videoId);

        if ($selectedVideo === null) {
            http_response_code(404);
            $videoNotFound = true;
        } elseif ($videoMode === 'editor') {
            try {
                $videoEditorService = new VideoEditorService($videoRepository, new VideoCutPointRepository($pdo), new VideoEditSegmentRepository($pdo));
                $videoCutPoints = $videoEditorService->list($userId, $videoId);
                $segmentCollection = $videoEditorService->listSegments($userId, $videoId);
                $videoSegments = is_array($segmentCollection['segments'] ?? null) ? $segmentCollection['segments'] : [];
                $videoSegmentSummary = is_array($segmentCollection['summary'] ?? null) ? $segmentCollection['summary'] : null;
                $videoExportJobs = (new VideoExportService($videoRepository, $videoEditorService, new VideoExportJobRepository($pdo), new VideoStorage($videoConfig)))->list($userId, $videoId);
                $videoTranscriptions = (new VideoTranscriptionService(
                    new VideoTranscriptionRepository($pdo),
                    new TranscriptionSourceResolver($videoRepository, new VideoExportJobRepository($pdo), new VideoStorage($videoConfig)),
                    new WhisperService($videoConfig, new VideoStorage($videoConfig)),
                ))->list($userId, $videoId);
            } catch (VideoValidationException $exception) {
                $videoEditorError = $exception->getMessage();
            }
        } else {
            $videoTranscriptions = (new VideoTranscriptionService(
                new VideoTranscriptionRepository($pdo),
                new TranscriptionSourceResolver($videoRepository, new VideoExportJobRepository($pdo), new VideoStorage($videoConfig)),
                new WhisperService($videoConfig, new VideoStorage($videoConfig)),
            ))->list($userId, $videoId);
        }
    }
}

if ($page === null) {
    http_response_code(404);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::escape(($page['title'] ?? 'Seccion no encontrada') . ' - Mi Central') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Saltar al contenido</a>
<?php
View::render('layout/header', [
    'appName' => $config['app']['name'],
    'username' => $username,
    'notifications' => $notificationSummary,
]);
?>
<div class="app-shell">
    <?php
    View::render('layout/sidebar', [
        'navigationGroups' => $navigationGroups,
        'activeSection' => $activeSection,
    ]);
    ?>
    <div class="app-content">
        <main class="content-panel" id="main-content" tabindex="-1">
            <?php
            if ($page === null) {
                View::render('pages/not-found');
            } elseif ($page['key'] === 'home') {
                View::render('pages/dashboard', [
                    'username' => $username,
                    'summary' => $dashboardSummary,
                ]);
            } elseif ($page['key'] === 'organization') {
                View::render('pages/organization-tasks', [
                    'tasks' => $organizationTasks,
                    'spaces' => $organizationSpaces,
                    'filters' => $taskFilters['ui'] ?? [],
                    'filterError' => $taskFilterError,
                    'mode' => $taskPageMode,
                    'projects' => $organizationProjects,
                    'selectedProject' => $selectedProject,
                    'selectedProjectTasks' => $selectedProjectTasks,
                    'notes' => $organizationNotes,
                    'reminders' => $organizationReminders,
                    'calendar' => $organizationCalendar,
                    'taskPrefill' => $organizationTaskPrefill,
                ]);
            } elseif ($page['key'] === 'notifications') {
                View::render('pages/notifications', [
                    'notificationsPage' => $notificationsPage,
                ]);
            } elseif ($page['key'] === 'friends') {
                View::render('pages/friends', [
                    'friends' => $friends,
                    'activeFriends' => $activeFriends,
                    'scheduleEntries' => $friendScheduleEntries,
                    'nowItems' => $friendNowItems,
                    'todayItems' => $friendTodayItems,
                    'week' => $friendWeek,
                    'coincidences' => $friendCoincidences,
                    'filters' => $friendFilters,
                ]);
            } elseif ($page['key'] === 'video-editor') {
                View::render('pages/video', [
                    'videos' => $videoFiles,
                    'videoConfig' => $videoConfig,
                    'selectedVideo' => $selectedVideo,
                    'videoNotFound' => $videoNotFound,
                    'videoMode' => $videoMode,
                    'videoCutPoints' => $videoCutPoints,
                    'videoSegments' => $videoSegments,
                    'videoSegmentSummary' => $videoSegmentSummary,
                    'videoExportJobs' => $videoExportJobs,
                    'videoTranscriptions' => $videoTranscriptions,
                    'videoEditorError' => $videoEditorError,
                ]);
            } else {
                View::render('pages/placeholder', [
                    'page' => $page,
                ]);
            }

            View::render('layout/footer');
            ?>
