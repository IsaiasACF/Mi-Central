<?php
declare(strict_types=1);

use App\Http\RequireAuth;
use App\Http\Csrf;
use App\Http\SecurityHeaders;
use App\Http\Session;
use App\Support\Navigation;
use App\Support\DateTimeHelper;
use App\Support\View;
use App\Database\Connection;
use App\Services\AuthService;
use App\Services\UserAdminService;
use Modules\Dashboard\DashboardSummaryService;
use Modules\Discounts\DiscountBenefitProgramRepository;
use Modules\Discounts\DiscountBenefitProgramService;
use Modules\Discounts\DiscountBenefitType;
use Modules\Discounts\DiscountCompatibilityService;
use Modules\Discounts\DiscountDiscoveryService;
use Modules\Discounts\DiscountMerchantRepository;
use Modules\Discounts\DiscountMerchantService;
use Modules\Discounts\DiscountPromotionAvailabilityService;
use Modules\Discounts\DiscountPromotionCategory;
use Modules\Discounts\DiscountPromotionFormat;
use Modules\Discounts\DiscountPromotionRepository;
use Modules\Discounts\DiscountPromotionService;
use Modules\Discounts\UserDiscountBenefitRepository;
use Modules\Discounts\UserDiscountBenefitService;
use Modules\Expenses\ExpenseCategoryRepository;
use Modules\Expenses\ExpenseCategoryService;
use Modules\Expenses\ExpenseHistoryService;
use Modules\Expenses\ExpensePaymentMethodRepository;
use Modules\Expenses\ExpensePaymentMethodService;
use Modules\Expenses\ExpenseMonthlySummaryService;
use Modules\Expenses\ExpenseRecurringAdjustmentRepository;
use Modules\Expenses\ExpenseRecurringAdjustmentService;
use Modules\Expenses\ExpenseRepository;
use Modules\Expenses\ExpenseService as MonthlyExpenseService;
use Modules\Expenses\ExpenseServiceDefinitionService;
use Modules\Expenses\ExpenseServiceRepository;
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
use Modules\Organization\LabelRepository;
use Modules\Organization\LabelService;
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
use Modules\Video\VideoEditorService;
use Modules\Video\VideoExportJobRepository;
use Modules\Video\VideoExportService;
use Modules\Video\VideoDashboardSummaryService;
use Modules\Video\VideoRepository;
use Modules\Video\VideoService;
use Modules\Video\VideoStorage;
use Modules\Video\VideoValidationException;

/**
 * @param array<string, mixed> $request
 * @param array<int, array<string, mixed>> $spaces
 * @param array<string, mixed> $appConfig
 * @return array{ui: array<string, string>, service: array<string, string>, project_service: array<string, string>, note_service: array<string, string>, reminder_service: array<string, string>, calendar: array<string, string>}
 */
function organizationFiltersFromRequest(array $request, array $spaces, array $labels, array $appConfig): array
{
    $tab = is_string($request['tab'] ?? null) ? $request['tab'] : 'tasks';
    $legacyType = is_string($request['type'] ?? null) ? $request['type'] : '';
    $status = is_string($request['status'] ?? null) ? $request['status'] : 'pending';
    $space = is_string($request['space'] ?? null) ? $request['space'] : 'all';
    $label = is_string($request['label'] ?? null) ? $request['label'] : 'all';
    $sort = is_string($request['sort'] ?? null) ? $request['sort'] : 'deadline';
    $time = is_string($request['time'] ?? null) ? $request['time'] : 'all';
    $spaceIdsBySlug = [];
    $labelIds = [];

    foreach ($spaces as $spaceRow) {
        if (isset($spaceRow['slug'], $spaceRow['id'])) {
            $spaceIdsBySlug[(string) $spaceRow['slug']] = (string) $spaceRow['id'];
        }
    }

    foreach ($labels as $labelRow) {
        if (isset($labelRow['id'])) {
            $labelIds[(string) $labelRow['id']] = true;
        }
    }

    if ($legacyType === 'projects') {
        $tab = 'projects';
    }

    if (!in_array($tab, ['tasks', 'projects', 'notes', 'reminders', 'calendar', 'labels'], true)) {
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
    } elseif ($tab === 'notes') {
        if (!in_array($status, ['active', 'completed', 'all'], true)) {
            $status = 'active';
        }
    } elseif ($tab === 'calendar' || $tab === 'labels') {
        $status = 'all';
    } elseif (!in_array($status, ['pending', 'completed', 'all'], true)) {
        $status = 'pending';
    }

    if ($space !== 'all' && $space !== 'inbox' && !array_key_exists($space, $spaceIdsBySlug)) {
        $space = 'all';
    }

    if ($label !== 'all' && !isset($labelIds[$label])) {
        $label = 'all';
    }

    if (!in_array($sort, ['default', 'deadline'], true)) {
        $sort = 'deadline';
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

    if ($tab === 'reminders' || $tab === 'calendar' || $tab === 'labels') {
        $time = 'all';
    }

    if ($label !== 'all') {
        $service['label_id'] = $label;
        $projectService['label_id'] = $label;
        $noteService['label_id'] = $label;
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

    if ($sort === 'deadline') {
        $service['sort'] = 'deadline';
        $projectService['sort'] = 'deadline';
    }

    if ($tab === 'notes' && $status !== 'all') {
        $noteService['status'] = $status;
    }

    if ($tab === 'reminders') {
        $reminderService['status'] = $status;
    }

    return [
        'ui' => [
            'tab' => $tab,
            'status' => $status,
            'space' => $space,
            'label' => $label,
            'sort' => $sort,
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

$videoEnabled = filter_var(getenv('VIDEO_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN);

if ($videoEnabled === false && is_string($section) && str_starts_with($section, 'video')) {
    http_response_code(404);
    echo 'Video no disponible en este entorno.';
    exit;
}

if (in_array($section, [
    'discounts-for-me',
    'discounts-today',
    'discounts-all',
    'discounts-benefits',
    'discounts-favorites',
    'discounts-sources',
], true)) {
    $legacyDiscountTabs = [
        'discounts-for-me' => 'for-me',
        'discounts-today' => 'for-me',
        'discounts-all' => 'all',
        'discounts-benefits' => 'profile-benefits',
        'discounts-favorites' => 'favorites',
        'discounts-sources' => 'all',
    ];
    $legacyTab = $legacyDiscountTabs[$section] ?? 'for-me';
    $target = $legacyTab === 'profile-benefits'
        ? '/index.php?section=settings&tab=benefits'
        : '/index.php?section=discounts';

    if ($legacyTab !== 'for-me' && $legacyTab !== 'profile-benefits') {
        $target .= '&tab=' . rawurlencode($legacyTab);
    }

    header('Location: ' . $target, true, 302);
    exit;
}

if (in_array($section, ['video-editor', 'video-processing'], true)) {
    $target = '/index.php?section=video';

    if ($section === 'video-processing') {
        $target .= '&tab=processings';
    }

    if (isset($_GET['tab']) && $_GET['tab'] === 'processed') {
        $target = '/index.php?section=video&tab=processings';
    }

    foreach (['id', 'editor'] as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key]) && $_GET[$key] !== '') {
            $target .= '&' . rawurlencode($key) . '=' . rawurlencode((string) $_GET[$key]);
        }
    }

    header('Location: ' . $target, true, 302);
    exit;
}

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
$organizationLabels = [];
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
$videoProcessedExports = [];
$videoEditorError = null;
$discountUserBenefits = [];
$discountBenefitPrograms = [];
$discountBenefitTypeLabels = DiscountBenefitType::labels();
$discountMerchants = [];
$discountAvailableCategories = [];
$discountPromotions = [];
$discountDiscoveryPromotions = [];
$discountDiscoveryError = '';
$discountUserBenefitCount = 0;
$discountPromotionFilters = [
    'tab' => 'for-me',
    'status' => 'active',
    'merchant_id' => '',
    'benefit_program_id' => '',
    'weekday' => '',
    'search' => '',
    'channel' => '',
    'category' => '',
    'collector_key' => '',
    'state' => 'available',
];
$discountPromotionLabels = [
    'discount_types' => DiscountPromotionFormat::discountTypeLabels(),
    'channels' => DiscountPromotionFormat::channelLabels(),
    'weekdays' => DiscountPromotionFormat::WEEKDAYS,
    'categories' => DiscountPromotionCategory::labels(),
];
$expensesConfiguration = [
    'active_tab' => 'categories',
    'categories' => [],
    'active_categories' => [],
    'payment_methods' => [],
    'active_payment_methods' => [],
    'services' => [],
    'active_services' => [],
    'payment_method_type_labels' => [
        'card' => 'Tarjeta',
        'bank_account' => 'Cuenta bancaria',
        'automatic_payment' => 'Pago automatico / PAT-PAC',
        'wallet' => 'Billetera digital',
        'webpay' => 'WebPay',
        'transfer' => 'Transferencia',
        'cash' => 'Efectivo',
        'other' => 'Otro',
    ],
];
$expensesMonth = [
    'mode' => 'month',
    'active_tab' => 'current',
    'period_month' => DateTimeHelper::nowLocal()->format('Y-m-01'),
    'month_value' => DateTimeHelper::nowLocal()->format('Y-m'),
    'month_label' => '',
    'previous_month' => '',
    'next_month' => '',
    'current_month' => DateTimeHelper::nowLocal()->format('Y-m'),
    'today' => DateTimeHelper::nowLocal()->format('Y-m-d'),
    'focused_expense_id' => '',
    'items' => [],
    'summary' => [
        'total_amount_clp' => 0,
        'paid_amount_clp' => 0,
        'pending_amount_clp' => 0,
        'overdue_amount_clp' => 0,
        'cancelled_amount_clp' => 0,
        'counts' => ['total' => 0, 'paid' => 0, 'pending' => 0, 'overdue' => 0, 'cancelled' => 0],
    ],
    'dashboard_summary' => [
        'expense_count' => 0,
        'total_known_clp' => 0,
        'paid_known_clp' => 0,
        'pending_known_clp' => 0,
        'overdue_known_clp' => 0,
        'unknown_amount_count' => 0,
        'paid_percentage' => 0,
        'category_breakdown' => [],
        'payment_method_breakdown' => [],
        'upcoming_due' => [],
        'overdue_items' => [],
    ],
    'filtered_summary' => [
        'total_amount_clp' => 0,
        'paid_amount_clp' => 0,
        'pending_amount_clp' => 0,
        'overdue_amount_clp' => 0,
        'cancelled_amount_clp' => 0,
        'counts' => ['total' => 0, 'paid' => 0, 'pending' => 0, 'overdue' => 0, 'cancelled' => 0],
    ],
    'filters' => ['status' => '', 'category_id' => '', 'service_id' => '', 'payment_method_id' => '', 'search' => ''],
    'sort' => 'due',
];
$expensesHistory = [
    'range' => ExpenseHistoryService::RANGE_6_MONTHS,
    'range_label' => 'Ultimos 6 meses',
    'range_options' => ExpenseHistoryService::rangeLabels(),
    'start_month' => '',
    'end_month' => '',
    'months_included' => 6,
    'filters' => ['category_id' => null, 'service_id' => null, 'payment_method_id' => null],
    'has_any_data' => false,
    'has_comparison' => false,
    'total_known_clp' => 0,
    'total_paid_clp' => 0,
    'average_monthly_clp' => 0,
    'highest_month' => null,
    'lowest_month' => null,
    'monthly_totals' => [],
    'monthly_unknown_count' => 0,
    'category_breakdown' => [],
    'service_breakdown' => [],
    'payment_method_breakdown' => [],
    'status_breakdown' => ['paid_clp' => 0, 'pending_clp' => 0, 'overdue_clp' => 0, 'unknown_amount_count' => 0],
    'category_series' => [],
    'service_series' => [],
    'month_details' => [],
];
$settingsUsers = [
    'is_admin' => false,
    'users' => [],
    'message' => '',
    'error' => '',
];
$settingsBenefits = [
    'programs' => [],
    'selected_program_ids' => [],
    'type_labels' => $discountBenefitTypeLabels,
    'filters' => [
        'search' => '',
        'provider' => '',
        'benefit_type' => '',
    ],
    'message' => '',
    'error' => '',
];
$settingsTab = 'benefits';
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

if (is_array($page) && $page['key'] === 'settings') {
    $pdo = Connection::get();
    $authService = new AuthService($pdo);
    $userAdminService = new UserAdminService($pdo);
    $settingsUsers['is_admin'] = $authService->isAdmin($userId);
    $settingsTab = is_string($_GET['tab'] ?? null) ? (string) $_GET['tab'] : 'benefits';

    if (!in_array($settingsTab, ['benefits', 'users'], true)) {
        $settingsTab = 'benefits';
    }

    if ($settingsTab === 'users' && !$settingsUsers['is_admin']) {
        $settingsTab = 'benefits';
    }

    $programRepository = new DiscountBenefitProgramRepository($pdo);
    $programService = new DiscountBenefitProgramService($programRepository);
    $userBenefitService = new UserDiscountBenefitService(new UserDiscountBenefitRepository($pdo), $programService);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::validate($csrfToken)) {
            http_response_code(400);
            echo 'Solicitud invalida.';
            exit;
        }

        $settingsAction = is_string($_POST['settings_action'] ?? null) ? (string) $_POST['settings_action'] : '';

        if ($settingsAction === 'toggle_benefit') {
            $programId = is_string($_POST['benefit_program_id'] ?? null) && preg_match('/\A[1-9][0-9]*\z/', (string) $_POST['benefit_program_id']) === 1
                ? (int) $_POST['benefit_program_id']
                : 0;
            $enabled = (string) ($_POST['enabled'] ?? '0') === '1';
            $result = 'benefit_invalid';

            try {
                $result = $userBenefitService->setProgramActive($userId, $programId, $enabled)
                    ? ($enabled ? 'benefit_added' : 'benefit_removed')
                    : 'benefit_invalid';
            } catch (\Throwable) {
                $result = 'benefit_invalid';
            }

            header('Location: /index.php?section=settings&tab=benefits&result=' . rawurlencode($result), true, 303);
            exit;
        }

        if (!$settingsUsers['is_admin']) {
            http_response_code(403);
            echo 'No autorizado.';
            exit;
        }

        $action = is_string($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
        $targetUserId = is_string($_POST['user_id'] ?? null) && preg_match('/\A[1-9][0-9]*\z/', (string) $_POST['user_id']) === 1
            ? (int) $_POST['user_id']
            : 0;
        $result = 'invalid';

        try {
            $ok = match ($action) {
                'deactivate' => $userAdminService->deactivate($targetUserId, $userId),
                'reactivate' => $userAdminService->reactivate($targetUserId),
                default => false,
            };
            $result = $ok ? $action : 'unchanged';
        } catch (\RuntimeException) {
            $result = 'self';
        }

        header('Location: /index.php?section=settings&tab=users&result=' . rawurlencode($result), true, 303);
        exit;
    }

    if ($settingsUsers['is_admin']) {
        $settingsUsers['users'] = $userAdminService->users();
    }

    $settingsBenefits['filters'] = [
        'search' => is_string($_GET['benefit_search'] ?? null) ? trim((string) $_GET['benefit_search']) : '',
        'provider' => is_string($_GET['benefit_provider'] ?? null) ? trim((string) $_GET['benefit_provider']) : '',
        'benefit_type' => is_string($_GET['benefit_type'] ?? null) ? trim((string) $_GET['benefit_type']) : '',
    ];
    $settingsBenefits['programs'] = $programService->listActive();
    $settingsBenefits['selected_program_ids'] = array_fill_keys(
        array_map(static fn (array $benefit): int => (int) ($benefit['benefit_program_id'] ?? 0), $userBenefitService->listActive($userId)),
        true,
    );

    $result = is_string($_GET['result'] ?? null) ? (string) $_GET['result'] : '';
    $settingsUsers['message'] = match ($result) {
        'deactivate' => 'Usuario desactivado.',
        'reactivate' => 'Usuario reactivado.',
        default => '',
    };
    $settingsUsers['error'] = match ($result) {
        'self' => 'No puedes aplicar esa accion sobre tu propia cuenta.',
        'unchanged' => 'No se realizaron cambios.',
        'invalid' => 'Accion invalida.',
        default => '',
    };
    $settingsBenefits['message'] = match ($result) {
        'benefit_added' => 'Beneficio agregado a tu perfil.',
        'benefit_removed' => 'Beneficio quitado de tu perfil.',
        default => '',
    };
    $settingsBenefits['error'] = $result === 'benefit_invalid'
        ? 'No se pudo actualizar ese beneficio.'
        : '';
}

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
    $dashboardSummary = (new DashboardSummaryService($config['app'], $taskService, $userId, $reminderService, $notificationService, $friendPresenceService, $coincidenceService, new VideoDashboardSummaryService($pdo)))->summary();
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
    $labelService = new LabelService(new LabelRepository($pdo));
    $taskService = new TaskService(new TaskRepository($pdo), (string) ($config['app']['timezone'] ?? 'America/Santiago'), $labelService);
    $projectService = new ProjectService(new ProjectRepository($pdo), (string) ($config['app']['timezone'] ?? 'America/Santiago'), $labelService);
    $noteService = new NoteService(new NoteRepository($pdo), $labelService);
    $reminderService = new ReminderService(new ReminderRepository($pdo), (string) ($config['app']['timezone'] ?? 'America/Santiago'));
    $calendarService = new CalendarService(new CalendarRepository($pdo), (string) ($config['app']['timezone'] ?? 'America/Santiago'), $labelService);
    $spaceRepository = new SpaceRepository($pdo);
    $organizationSpaces = $spaceRepository->listForUser($userId);
    $organizationLabels = $labelService->list($userId);
    $taskPageMode = 'organization';
    $taskFilters = organizationFiltersFromRequest($_GET, $organizationSpaces, $organizationLabels, $config['app']);

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

if (is_array($page) && $page['key'] === 'discounts') {
    $pdo = Connection::get();
    $programRepository = new DiscountBenefitProgramRepository($pdo);
    $userBenefitRepository = new UserDiscountBenefitRepository($pdo);
    $promotionRepository = new DiscountPromotionRepository($pdo);
    $programService = new DiscountBenefitProgramService($programRepository);
    $merchantService = new DiscountMerchantService(new DiscountMerchantRepository($pdo));
    $promotionService = new DiscountPromotionService($promotionRepository, $merchantService, $programService);
    $userBenefitService = new UserDiscountBenefitService($userBenefitRepository, $programService);
    $compatibilityService = new DiscountCompatibilityService($promotionRepository, $userBenefitRepository);
    $availabilityService = new DiscountPromotionAvailabilityService((string) ($config['app']['timezone'] ?? 'America/Santiago'));
    $discoveryService = new DiscountDiscoveryService($promotionRepository, $compatibilityService, $availabilityService);
    $discountTab = is_string($_GET['tab'] ?? null) ? (string) $_GET['tab'] : 'for-me';

    if ($discountTab === 'benefits') {
        header('Location: /index.php?section=settings&tab=benefits', true, 302);
        exit;
    }

    if ($discountTab === 'today') {
        header('Location: /index.php?section=discounts', true, 302);
        exit;
    }

    if ($discountTab === 'promotions') {
        header('Location: /index.php?section=discounts&tab=all', true, 302);
        exit;
    }

    if (!in_array($discountTab, ['for-me', 'favorites', 'all'], true)) {
        $discountTab = 'for-me';
    }

    $discountPromotionFilters = [
        'tab' => $discountTab,
        'status' => is_string($_GET['status'] ?? null) ? (string) $_GET['status'] : 'active',
        'merchant_id' => is_string($_GET['merchant_id'] ?? null) ? (string) $_GET['merchant_id'] : '',
        'benefit_program_id' => is_string($_GET['benefit_program_id'] ?? null) ? (string) $_GET['benefit_program_id'] : '',
        'weekday' => is_string($_GET['weekday'] ?? null) ? (string) $_GET['weekday'] : '',
        'search' => is_string($_GET['search'] ?? null) ? (string) $_GET['search'] : '',
        'channel' => is_string($_GET['channel'] ?? null) ? (string) $_GET['channel'] : '',
        'category' => is_string($_GET['category'] ?? null) ? (string) $_GET['category'] : '',
        'collector_key' => is_string($_GET['collector_key'] ?? null) ? (string) $_GET['collector_key'] : '',
        'state' => is_string($_GET['state'] ?? null) ? (string) $_GET['state'] : 'available',
    ];
    $discountUserBenefits = $userBenefitService->listActive($userId);
    $compatibilityService->primeActiveUserBenefits($userId, $discountUserBenefits);
    $discountUserBenefitCount = count($discountUserBenefits);
    $discountBenefitPrograms = $programService->listActive();
    $discountMerchants = $merchantService->listActive();
    $discountAvailableCategories = $promotionRepository->listVisibleCategoriesForUser($userId);

    try {
        if ($discountTab === 'all') {
            $discountDiscoveryPromotions = $discoveryService->all($userId, $discountPromotionFilters);
        } elseif ($discountTab === 'favorites') {
            $discountDiscoveryPromotions = $discoveryService->favorites($userId, $discountPromotionFilters);
        } elseif ($discountTab === 'for-me') {
            $discountDiscoveryPromotions = $discoveryService->forMe($userId, $discountPromotionFilters);
        }
    } catch (\Throwable $exception) {
        error_log('Discount discovery failed: ' . $exception->getMessage());
        $discountDiscoveryPromotions = [];
        $discountDiscoveryError = 'No se pudieron cargar los descuentos con esos filtros.';
    }
}

if (is_array($page) && $page['key'] === 'expenses') {
    $pdo = Connection::get();
    $categoryService = new ExpenseCategoryService(new ExpenseCategoryRepository($pdo));
    $paymentMethodService = new ExpensePaymentMethodService(new ExpensePaymentMethodRepository($pdo));
    $serviceDefinitionService = new ExpenseServiceDefinitionService(new ExpenseServiceRepository($pdo));
    $recurringAdjustmentService = new ExpenseRecurringAdjustmentService(
        new ExpenseRecurringAdjustmentRepository($pdo),
        (string) ($config['app']['timezone'] ?? DateTimeHelper::DEFAULT_TIMEZONE),
    );
    $monthlyExpenseService = new MonthlyExpenseService(new ExpenseRepository($pdo));
    $monthlySummaryService = new ExpenseMonthlySummaryService($pdo, (string) ($config['app']['timezone'] ?? DateTimeHelper::DEFAULT_TIMEZONE));
    $historyService = new ExpenseHistoryService($pdo, (string) ($config['app']['timezone'] ?? DateTimeHelper::DEFAULT_TIMEZONE));
    $expensesTab = is_string($_GET['tab'] ?? null) ? (string) $_GET['tab'] : 'current';
    $configurationTabs = ['categories', 'payment-methods', 'services', 'import'];
    $expensesMode = $expensesTab === 'history'
        ? 'history'
        : (in_array($expensesTab, $configurationTabs, true) || $expensesTab === 'settings' ? 'settings' : 'month');
    $configurationTab = in_array($expensesTab, $configurationTabs, true) ? $expensesTab : 'categories';

    $expenseServices = $serviceDefinitionService->list($userId);
    $activeExpenseServices = array_values(array_filter(
        $expenseServices,
        static fn (array $service): bool => (int) ($service['active'] ?? 0) === 1,
    ));
    $recurringAdjustmentsByService = $recurringAdjustmentService->listForServices(
        $userId,
        array_map(static fn (array $service): int => (int) ($service['id'] ?? 0), $expenseServices),
    );

    foreach ($expenseServices as $index => $service) {
        $expenseServices[$index]['recurring_adjustments'] = $recurringAdjustmentsByService[(int) ($service['id'] ?? 0)] ?? [];
    }

    foreach ($activeExpenseServices as $index => $service) {
        $activeExpenseServices[$index]['recurring_adjustments'] = $recurringAdjustmentsByService[(int) ($service['id'] ?? 0)] ?? [];
    }

    $expenseCategories = $categoryService->list($userId);
    $expensePaymentMethods = $paymentMethodService->list($userId);
    $expensesConfiguration = [
        'active_tab' => $configurationTab,
        'categories' => $expenseCategories,
        'active_categories' => array_values(array_filter(
            $expenseCategories,
            static fn (array $category): bool => (int) ($category['active'] ?? 0) === 1,
        )),
        'payment_methods' => $expensePaymentMethods,
        'active_payment_methods' => array_values(array_filter(
            $expensePaymentMethods,
            static fn (array $method): bool => (int) ($method['active'] ?? 0) === 1,
        )),
        'services' => $expenseServices,
        'active_services' => $activeExpenseServices,
        'payment_method_type_labels' => $expensesConfiguration['payment_method_type_labels'],
    ];

    $today = DateTimeHelper::nowLocal();
    $currentMonth = $today->format('Y-m');
    $monthValue = is_string($_GET['month'] ?? null) ? trim((string) $_GET['month']) : $currentMonth;

    if (preg_match('/\A(19|20|21|22)[0-9]{2}-(0[1-9]|1[0-2])\z/', $monthValue) !== 1) {
        $monthValue = $currentMonth;
    }

    $monthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $monthValue . '-01', DateTimeHelper::timezone());
    $monthDate = $monthDate instanceof DateTimeImmutable ? $monthDate : $today->modify('first day of this month');
    $monthNames = [
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
    ];
    $expenseFilters = [
        'status' => is_string($_GET['status'] ?? null) ? (string) $_GET['status'] : '',
        'category_id' => is_string($_GET['category_id'] ?? null) ? (string) $_GET['category_id'] : '',
        'service_id' => is_string($_GET['service_id'] ?? null) ? (string) $_GET['service_id'] : '',
        'payment_method_id' => is_string($_GET['payment_method_id'] ?? null) ? (string) $_GET['payment_method_id'] : '',
        'search' => is_string($_GET['search'] ?? null) ? trim((string) $_GET['search']) : '',
    ];
    $expenseSort = is_string($_GET['sort'] ?? null) ? (string) $_GET['sort'] : 'due';
    $focusedExpenseId = is_string($_GET['expense'] ?? null) && preg_match('/\A[1-9][0-9]*\z/', (string) $_GET['expense']) === 1
        ? (string) $_GET['expense']
        : '';
    $historyFilters = [
        'range' => is_string($_GET['range'] ?? null) ? (string) $_GET['range'] : ExpenseHistoryService::RANGE_6_MONTHS,
        'category_id' => is_string($_GET['category_id'] ?? null) ? (string) $_GET['category_id'] : '',
        'service_id' => is_string($_GET['service_id'] ?? null) ? (string) $_GET['service_id'] : '',
        'payment_method_id' => is_string($_GET['payment_method_id'] ?? null) ? (string) $_GET['payment_method_id'] : '',
    ];

    try {
        $monthData = $monthlyExpenseService->listForMonth(
            $userId,
            (int) $monthDate->format('Y'),
            (int) $monthDate->format('n'),
            $today,
            $expenseFilters,
            $expenseSort,
        );
        $monthlySummary = $monthlySummaryService->summary($userId, $monthData['period_month'], $today);
        $expensesHistory = $historyService->history($userId, $historyFilters, $today);
    } catch (\Throwable $exception) {
        error_log('Expenses monthly page failed: ' . $exception->getMessage());
        $expenseFilters = ['status' => '', 'category_id' => '', 'service_id' => '', 'payment_method_id' => '', 'search' => ''];
        $expenseSort = 'due';
        $monthData = $monthlyExpenseService->listForMonth(
            $userId,
            (int) $monthDate->format('Y'),
            (int) $monthDate->format('n'),
            $today,
            $expenseFilters,
            $expenseSort,
        );
        $monthlySummary = $monthlySummaryService->summary($userId, $monthData['period_month'], $today);
        $expensesHistory = $historyService->history($userId, ['range' => ExpenseHistoryService::RANGE_6_MONTHS], $today);
    }

    $expensesMonth = [
        'mode' => $expensesMode,
        'active_tab' => $expensesMode === 'month' ? 'current' : $expensesMode,
        'period_month' => $monthData['period_month'],
        'month_value' => $monthDate->format('Y-m'),
        'month_label' => ($monthNames[(int) $monthDate->format('n')] ?? $monthDate->format('F')) . ' ' . $monthDate->format('Y'),
        'previous_month' => $monthDate->modify('-1 month')->format('Y-m'),
        'next_month' => $monthDate->modify('+1 month')->format('Y-m'),
        'current_month' => $currentMonth,
        'today' => $today->format('Y-m-d'),
        'focused_expense_id' => $focusedExpenseId,
        'items' => $monthData['items'],
        'all_items' => $monthData['all_items'] ?? $monthData['items'],
        'summary' => [
            'total_amount_clp' => $monthData['total_amount_clp'],
            'paid_amount_clp' => $monthData['paid_amount_clp'],
            'pending_amount_clp' => $monthData['pending_amount_clp'],
            'overdue_amount_clp' => $monthData['overdue_amount_clp'],
            'cancelled_amount_clp' => $monthData['cancelled_amount_clp'],
            'unknown_amount_count' => $monthData['unknown_amount_count'],
            'pending_unknown_amount_count' => $monthData['pending_unknown_amount_count'],
            'overdue_unknown_amount_count' => $monthData['overdue_unknown_amount_count'],
            'paid_unknown_amount_count' => $monthData['paid_unknown_amount_count'],
            'cancelled_unknown_amount_count' => $monthData['cancelled_unknown_amount_count'],
            'counts' => $monthData['counts'],
        ],
        'dashboard_summary' => $monthlySummary,
        'filtered_summary' => $monthData['filtered_summary'],
        'filters' => $expenseFilters,
        'sort' => $monthData['sort'],
    ];
}

if (is_array($page) && $page['key'] === 'video') {
    $videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
    $pdo = Connection::get();
    $videoRepository = new VideoRepository($pdo);
    $videoService = new VideoService($videoRepository, $videoConfig);
    $videoId = is_string($_GET['id'] ?? null) && preg_match('/\A[1-9][0-9]*\z/', (string) $_GET['id']) === 1
        ? (int) $_GET['id']
        : null;

    if ($videoId === null) {
        $videoTab = is_string($_GET['tab'] ?? null) ? (string) $_GET['tab'] : 'editor';

        if (!in_array($videoTab, ['editor', 'processings'], true)) {
            $videoTab = 'editor';
        }

        $videoMode = $videoTab === 'processings' ? 'processed' : 'list';

        if ($videoMode === 'processed') {
            $storage = new VideoStorage($videoConfig);
            $videoProcessedExports = (new VideoExportService(
                $videoRepository,
                new VideoEditorService($videoRepository, new VideoCutPointRepository($pdo), new VideoEditSegmentRepository($pdo)),
                new VideoExportJobRepository($pdo),
                $storage,
            ))->listProcessed($userId);
        } else {
            $videoFiles = $videoService->list($userId);
        }
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
            } catch (VideoValidationException $exception) {
                $videoEditorError = $exception->getMessage();
            }
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
    <link rel="icon" type="image/png" href="/brand/icon%20mi-central.png">
    <title><?= View::escape(($page['title'] ?? 'Seccion no encontrada') . ' - Mi Central') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= (string) (filemtime(dirname(__DIR__) . '/public/assets/css/app.css') ?: '1') ?>">
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
                    'labels' => $organizationLabels,
                    'taskPrefill' => $organizationTaskPrefill,
                ]);
            } elseif ($page['key'] === 'notifications') {
                View::render('pages/notifications', [
                    'notificationsPage' => $notificationsPage,
                ]);
            } elseif ($page['key'] === 'settings') {
                View::render('pages/settings', [
                    'settingsUsers' => $settingsUsers,
                    'settingsBenefits' => $settingsBenefits,
                    'activeTab' => $settingsTab,
                    'currentUserId' => $userId,
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
            } elseif ($page['key'] === 'discounts') {
                View::render('pages/discounts-discovery', [
                    'activeTab' => $discountPromotionFilters['tab'] ?? 'for-me',
                    'promotions' => $discountDiscoveryPromotions,
                    'merchants' => $discountMerchants,
                    'benefitPrograms' => $discountBenefitPrograms,
                    'availableCategories' => $discountAvailableCategories,
                    'promotionLabels' => $discountPromotionLabels,
                    'filters' => $discountPromotionFilters,
                    'userBenefitCount' => $discountUserBenefitCount,
                    'error' => $discountDiscoveryError,
                ]);
            } elseif ($page['key'] === 'expenses') {
                View::render('pages/expenses', [
                    'configuration' => $expensesConfiguration,
                    'month' => $expensesMonth,
                    'history' => $expensesHistory,
                ]);
            } elseif ($page['key'] === 'video') {
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
                    'videoProcessedExports' => $videoProcessedExports,
                    'videoEditorError' => $videoEditorError,
                ]);
            } else {
                View::render('pages/placeholder', [
                    'page' => $page,
                ]);
            }

            View::render('layout/footer');
            ?>
