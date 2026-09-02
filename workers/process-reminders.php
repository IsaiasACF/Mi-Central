<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Support\DateTimeHelper;
use Modules\Notifications\NotificationRepository;
use Modules\Organization\ReminderRepository;
use Modules\Organization\ReminderService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run from CLI.\n");
    exit(2);
}

$basePath = dirname(__DIR__);
$config = require $basePath . '/app/bootstrap.php';
$logPath = $basePath . '/storage/logs/reminders-worker.log';

try {
    $pdo = Connection::get();
    $reminders = new ReminderRepository($pdo);
    $notifications = new NotificationRepository($pdo);
    $reminderService = new ReminderService(
        $reminders,
        (string) ($config['app']['timezone'] ?? 'America/Santiago'),
    );
    $nowUtc = DateTimeHelper::nowUtcStorage();
    $summary = [
        'processed' => 0,
        'created_notifications' => 0,
        'advanced_recurring' => 0,
        'errors' => 0,
    ];

    foreach ($reminders->dueIdsForProcessing($nowUtc) as $reminderId) {
        try {
            $pdo->beginTransaction();

            $reminder = $reminders->findDueForProcessing($reminderId, $nowUtc);

            if ($reminder === null) {
                $pdo->rollBack();
                continue;
            }

            $scheduledAt = is_string($reminder['next_remind_at'] ?? null) && $reminder['next_remind_at'] !== ''
                ? (string) $reminder['next_remind_at']
                : (string) $reminder['remind_at'];
            $created = $notifications->createReminderDue(
                (int) $reminder['user_id'],
                (int) $reminder['id'],
                (string) $reminder['title'],
                is_string($reminder['description'] ?? null) && $reminder['description'] !== '' ? (string) $reminder['description'] : null,
                $scheduledAt,
            );
            $update = reminderWorkerUpdate($reminderService, $reminder, $scheduledAt, $nowUtc);

            $reminders->update((int) $reminder['user_id'], (int) $reminder['id'], $update['data']);
            $pdo->commit();

            $summary['processed']++;

            if ($created) {
                $summary['created_notifications']++;
            }

            if ($update['advanced']) {
                $summary['advanced_recurring']++;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $summary['errors']++;
            reminderWorkerLog($logPath, 'Reminder ' . $reminderId . ' failed: ' . $exception->getMessage());
        }
    }

    echo 'Processed: ' . $summary['processed'] . PHP_EOL;
    echo 'Created notifications: ' . $summary['created_notifications'] . PHP_EOL;
    echo 'Advanced recurring reminders: ' . $summary['advanced_recurring'] . PHP_EOL;
    echo 'Errors: ' . $summary['errors'] . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    reminderWorkerLog($logPath, 'Critical failure: ' . $exception->getMessage());
    fwrite(STDERR, "Critical failure processing reminders.\n");
    exit(1);
}

/**
 * @param array<string, mixed> $reminder
 * @return array{data: array<string, mixed>, advanced: bool}
 */
function reminderWorkerUpdate(
    ReminderService $service,
    array $reminder,
    string $scheduledAtUtc,
    string $nowUtc,
): array {
    $type = (string) ($reminder['recurrence_type'] ?? 'none');

    if ($type === 'none') {
        return [
            'data' => [
                'status' => 'completed',
                'next_remind_at' => null,
            ],
            'advanced' => false,
        ];
    }

    $next = $scheduledAtUtc;
    $iterations = 0;

    do {
        $next = $service->calculateNextOccurrence(
            $next,
            $type,
            (int) ($reminder['recurrence_interval'] ?? 1),
            is_string($reminder['recurrence_until'] ?? null) ? (string) $reminder['recurrence_until'] : null,
            (string) $reminder['remind_at'],
        );
        $iterations++;
    } while ($next !== null && $next <= $nowUtc && $iterations < 100000);

    if ($iterations >= 100000) {
        throw new RuntimeException('Could not advance recurrence to a future occurrence.');
    }

    return [
        'data' => [
            'status' => $next === null ? 'completed' : 'pending',
            'next_remind_at' => $next,
        ],
        'advanced' => $next !== null,
    ];
}

function reminderWorkerLog(string $path, string $message): void
{
    $directory = dirname($path);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    error_log('[' . DateTimeHelper::nowUtcStorage() . ' UTC] ' . $message . PHP_EOL, 3, $path);
}
