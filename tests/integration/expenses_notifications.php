<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use App\Support\DateTimeHelper;
use Modules\Expenses\ExpenseNotificationFormatter;
use Modules\Expenses\ExpenseNotificationService;
use Modules\Expenses\ExpenseRepository;
use Modules\Expenses\ExpenseService;
use Modules\Notifications\NotificationRepository;
use Modules\Notifications\NotificationService;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$suffix = bin2hex(random_bytes(4));
$username = 'test_expense_notifications_' . $suffix;
$otherUsername = 'test_expense_notifications_other_' . $suffix;
$weeklyUsername = 'test_expense_notifications_weekly_' . $suffix;
$password = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-expense-notifications-');
$userIds = [];
$exitCode = 1;

function expense_notifications_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expense_notifications_http(string $url, string $method = 'GET', ?string $cookieFile = null, ?array $payload = null, array $headers = []): array
{
    $handle = curl_init($url);
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $requestHeaders = [];

    if ($body === false) {
        throw new RuntimeException('Could not encode request payload.');
    }

    foreach ($headers as $name => $value) {
        $requestHeaders[] = $name . ': ' . $value;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);

    if ($cookieFile !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        $requestHeaders[] = 'Content-Type: application/json';
    }

    if ($requestHeaders !== []) {
        curl_setopt($handle, CURLOPT_HTTPHEADER, $requestHeaders);
    }

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $responseBody = substr($response, $headerSize);
    $json = json_decode($responseBody, true);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => $responseBody,
        'json' => is_array($json) ? $json : null,
    ];
}

function expense_notifications_form(string $url, array $postFields, string $cookieFile): array
{
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function expense_notifications_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/data-notification-center[^>]+data-csrf-token="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function expense_notifications_index_exists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index'
    );
    $statement->execute(['table' => $table, 'index' => $index]);

    return (int) $statement->fetchColumn() >= 1;
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080006_create_notifications.php',
        '202608080027_extend_notifications_activity_center.php',
        '202608080028_create_expense_tables.php',
        '202608080029_create_expense_recurring_rules.php',
        '202608080032_create_expense_recurring_adjustments.php',
        '202608080030_add_expense_notification_index.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    expense_notifications_assert(expense_notifications_index_exists($pdo, 'expenses', 'expenses_user_status_due_index'), 'Expense notification index is missing.');

    $auth = new AuthService($pdo);
    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $password);
    $weeklyUserId = $auth->createUser($weeklyUsername, $password);
    $userIds = [$userId, $otherUserId, $weeklyUserId];
    $expenseService = new ExpenseService(new ExpenseRepository($pdo), DateTimeHelper::DEFAULT_TIMEZONE);
    $notificationRepository = new NotificationRepository($pdo);
    $notificationService = new NotificationService($notificationRepository, DateTimeHelper::DEFAULT_TIMEZONE);
    $worker = new ExpenseNotificationService(
        $pdo,
        $notificationRepository,
        new ExpenseNotificationFormatter(DateTimeHelper::DEFAULT_TIMEZONE),
        DateTimeHelper::DEFAULT_TIMEZONE,
    );
    $now = new DateTimeImmutable('2026-08-15 00:30:00', new DateTimeZone('America/Santiago'));

    $dueTomorrow = $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Aguas Andinas',
        'amount_clp' => '19.994',
        'due_on' => '2026-08-16',
        'status' => 'pending',
    ]);
    $dueTomorrowUnknown = $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Gas variable',
        'amount_clp' => '',
        'due_on' => '2026-08-16',
        'status' => 'pending',
    ]);
    $dueToday = $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Spotify',
        'amount_clp' => '8.250',
        'due_on' => '2026-08-15',
        'status' => 'pending',
    ]);
    $overdue = $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Entel',
        'amount_clp' => '10.996',
        'due_on' => '2026-08-12',
        'status' => 'pending',
    ]);
    $missingAmount = $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Electricidad',
        'amount_clp' => '',
        'due_on' => '2026-08-18',
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Pagado excluido',
        'amount_clp' => '5.000',
        'due_on' => '2026-08-15',
        'status' => 'paid',
        'paid_on' => '2026-08-14',
    ]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Cancelado excluido',
        'amount_clp' => '5.000',
        'due_on' => '2026-08-15',
        'status' => 'cancelled',
    ]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Monto ya informado',
        'amount_clp' => '7.000',
        'due_on' => '2026-08-18',
        'status' => 'pending',
    ]);
    $expenseService->create($userId, [
        'period_month' => '2026-08-01',
        'description' => 'Sin vencimiento',
        'amount_clp' => '',
        'status' => 'pending',
    ]);
    $otherExpense = $expenseService->create($otherUserId, [
        'period_month' => '2026-08-01',
        'description' => 'WOM otro usuario',
        'amount_clp' => '12.000',
        'due_on' => '2026-08-16',
        'status' => 'pending',
    ]);

    $first = $worker->process($now, [$userId, $otherUserId]);
    expense_notifications_assert($first['due_tomorrow'] === 3, 'Due tomorrow notifications were not created for both users.');
    expense_notifications_assert($first['due_today'] === 1, 'Due today notification was not created.');
    expense_notifications_assert($first['overdue'] === 1, 'Overdue notification was not created once.');
    expense_notifications_assert($first['missing_amount'] === 1, 'Missing amount notification was not created.');
    expense_notifications_assert($first['weekly_summaries'] === 0, 'Weekly summary should not run on Saturday.');
    expense_notifications_assert($first['errors'] === 0, 'Expense notification worker had errors.');

    $second = $worker->process($now, [$userId, $otherUserId]);
    expense_notifications_assert($second['due_tomorrow'] === 0 && $second['due_today'] === 0 && $second['overdue'] === 0 && $second['missing_amount'] === 0, 'Expense notifications were duplicated.');
    expense_notifications_assert($second['duplicates_skipped'] >= 6, 'Dedupe did not report skipped duplicates.');

    $items = $notificationService->list($userId, ['status' => 'all', 'limit' => 50]);
    $json = json_encode($items, JSON_THROW_ON_ERROR);
    expense_notifications_assert(str_contains($json, 'Aguas Andinas vence manana'), 'Due tomorrow title is missing.');
    expense_notifications_assert(str_contains($json, 'Gas variable vence manana') && str_contains($json, 'Monto pendiente'), 'Unknown amount was not shown correctly.');
    expense_notifications_assert(str_contains($json, 'Spotify vence hoy'), 'Due today title is missing.');
    expense_notifications_assert(str_contains($json, 'Entel esta vencido'), 'Overdue title is missing.');
    expense_notifications_assert(str_contains($json, 'Electricidad todavia no tiene monto'), 'Missing amount title is missing.');
    expense_notifications_assert(!str_contains($json, 'Pagado excluido') && !str_contains($json, 'Cancelado excluido') && !str_contains($json, 'Sin vencimiento'), 'Excluded expenses generated notifications.');
    expense_notifications_assert(str_contains($json, '/index.php?section=expenses&month=2026-08&expense=' . (int) $dueToday['id'] . '#expense-' . (int) $dueToday['id']), 'Expense notification target URL is wrong.');
    expense_notifications_assert(str_contains($json, 'Gasto: Spotify') && str_contains($json, 'Gastos'), 'Expense notification label or target label is wrong.');

    $otherItems = $notificationService->list($otherUserId, ['status' => 'all', 'limit' => 20]);
    $otherJson = json_encode($otherItems, JSON_THROW_ON_ERROR);
    expense_notifications_assert(str_contains($otherJson, 'WOM otro usuario vence manana'), 'Other user did not receive own expense notification.');
    expense_notifications_assert(!str_contains($otherJson, 'Aguas Andinas'), 'Other user received foreign expense notification.');
    expense_notifications_assert(!str_contains($json, 'WOM otro usuario'), 'Main user received foreign expense notification.');

    $expenseService->markPaid($userId, (int) $overdue['id'], '2026-08-15');
    $paidLater = $worker->process($now->modify('+1 hour'), [$userId, $otherUserId]);
    expense_notifications_assert($paidLater['overdue'] === 0, 'Paid overdue expense generated a new notification.');

    $pdo->prepare('UPDATE expenses SET status = "paid", paid_on = "2026-08-16" WHERE user_id IN (:user_id, :other_user_id)')
        ->execute(['user_id' => $userId, 'other_user_id' => $otherUserId]);

    $expenseService->create($weeklyUserId, [
        'period_month' => '2026-08-01',
        'description' => 'Resumen cuenta 1',
        'amount_clp' => '25.000',
        'due_on' => '2026-08-20',
        'status' => 'pending',
    ]);
    $expenseService->create($weeklyUserId, [
        'period_month' => '2026-08-01',
        'description' => 'Resumen cuenta 2',
        'amount_clp' => '20.000',
        'due_on' => '2026-08-21',
        'status' => 'pending',
    ]);
    $expenseService->create($weeklyUserId, [
        'period_month' => '2026-08-01',
        'description' => 'Resumen sin monto',
        'amount_clp' => '',
        'due_on' => '2026-08-22',
        'status' => 'pending',
    ]);

    $monday = new DateTimeImmutable('2026-08-17 09:00:00', new DateTimeZone('America/Santiago'));
    $weeklyFirst = $worker->process($monday, [$weeklyUserId]);
    $weeklySecond = $worker->process($monday->modify('+10 minutes'), [$weeklyUserId]);
    expense_notifications_assert($weeklyFirst['weekly_summaries'] === 1, 'Weekly expense summary was not created on Monday.');
    expense_notifications_assert($weeklySecond['weekly_summaries'] === 0 && $weeklySecond['duplicates_skipped'] >= 1, 'Weekly expense summary was not deduplicated.');
    $weeklyJson = json_encode($notificationService->list($weeklyUserId, ['status' => 'all', 'limit' => 20]), JSON_THROW_ON_ERROR);
    expense_notifications_assert(str_contains($weeklyJson, '3 gastos pendientes esta semana') && str_contains($weeklyJson, '$45.000') && str_contains($weeklyJson, '1 monto pendiente'), 'Weekly summary content is wrong.');

    $notificationRepository->createActivity($userId, NotificationRepository::TYPE_DAILY_AGENDA, 'activity', null, null, 'expense_notifications_mixed:' . $userId, 'Recordatorio mezclado', 'Mensaje recordatorio', '2026-08-15 12:00:00');
    $mixedJson = json_encode($notificationService->list($userId, ['status' => 'all', 'limit' => 50]), JSON_THROW_ON_ERROR);
    expense_notifications_assert(str_contains($mixedJson, 'Recordatorio mezclado') && str_contains($mixedJson, 'Spotify vence hoy'), 'Expense notifications did not mix with existing notification center items.');

    $loginPage = expense_notifications_http('http://127.0.0.1/login.php', 'GET', $cookieFile);
    expense_notifications_assert($loginPage['status'] === 200, 'Login page did not load.');
    $login = expense_notifications_form('http://127.0.0.1/login.php', [
        'csrf_token' => expense_notifications_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    expense_notifications_assert($login['status'] === 302, 'Could not log in.');
    $notificationsPage = expense_notifications_http('http://127.0.0.1/index.php?section=notifications&status=all', 'GET', $cookieFile);
    expense_notifications_assert($notificationsPage['status'] === 200 && str_contains($notificationsPage['body'], 'data-source-module="expenses"') && str_contains($notificationsPage['body'], 'Spotify vence hoy'), 'Notifications page did not render expense notifications.');
    $expenseTarget = expense_notifications_http('http://127.0.0.1/index.php?section=expenses&month=2026-08&expense=' . (int) $dueTomorrow['id'], 'GET', $cookieFile);
    expense_notifications_assert($expenseTarget['status'] === 200 && str_contains($expenseTarget['body'], 'id="expense-' . (int) $dueTomorrow['id'] . '"') && str_contains($expenseTarget['body'], 'is-focused'), 'Expense notification target did not open month with focused expense.');

    echo "Expenses notifications: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Expenses notifications: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if ($userIds !== []) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $cleanup = $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
        $cleanup->execute($userIds);
    }

    if (is_string($cookieFile) && is_file($cookieFile)) {
        unlink($cookieFile);
    }
}

exit($exitCode);
