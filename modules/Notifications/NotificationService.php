<?php
declare(strict_types=1);

namespace Modules\Notifications;

use App\Support\DateTimeHelper;
use Modules\Organization\TaskValidationException;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly string $timezone = DateTimeHelper::DEFAULT_TIMEZONE,
    )
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, array $filters = []): array
    {
        $normalized = [];
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : 'all';

        if (!in_array($status, ['all', 'unread', 'read'], true)) {
            $status = 'all';
        }

        if ($status !== 'all') {
            $normalized['status'] = $status;
        }

        if (isset($filters['limit'])) {
            $normalized['limit'] = $this->positiveId($filters['limit'], 'limit');
        }

        return array_map(
            fn (array $notification): array => $this->withDerivedData($notification),
            $this->notifications->listForUser($userId, $normalized),
        );
    }

    public function unreadCount(int $userId): int
    {
        return $this->notifications->unreadCountForUser($userId);
    }

    public function markRead(int $userId, int $notificationId): bool
    {
        return $this->notifications->markRead(
            $userId,
            $this->positiveId($notificationId, 'id'),
            DateTimeHelper::nowUtcStorage(),
        );
    }

    public function markAllRead(int $userId): int
    {
        return $this->notifications->markAllRead($userId, DateTimeHelper::nowUtcStorage());
    }

    /**
     * @param array<string, mixed> $notification
     * @return array<string, mixed>
     */
    private function withDerivedData(array $notification): array
    {
        $notification['is_read'] = is_string($notification['read_at'] ?? null) && $notification['read_at'] !== '';
        $notification['scheduled_at_local'] = DateTimeHelper::utcStorageToLocalStorage(
            is_string($notification['scheduled_at'] ?? null) ? (string) $notification['scheduled_at'] : null,
            $this->timezone,
        );
        $notification['read_at_local'] = DateTimeHelper::utcStorageToLocalStorage(
            is_string($notification['read_at'] ?? null) ? (string) $notification['read_at'] : null,
            $this->timezone,
        );
        $notification['type_label'] = $this->typeLabel((string) ($notification['type'] ?? ''));
        $notification['target_url'] = $this->targetUrl($notification);
        $notification['target_label'] = $this->targetLabel($notification);

        return $notification;
    }

    /**
     * @param array<string, mixed> $notification
     */
    private function targetUrl(array $notification): ?string
    {
        if (($notification['reminder_task_id'] ?? null) !== null) {
            $taskId = rawurlencode((string) $notification['reminder_task_id']);

            if (($notification['task_project_id'] ?? null) !== null) {
                return '/index.php?section=organization&tab=projects&project='
                    . rawurlencode((string) $notification['task_project_id'])
                    . '&edit_task=' . $taskId;
            }

            return '/index.php?section=organization&tab=tasks&status=all&edit_task=' . $taskId;
        }

        if (($notification['reminder_project_id'] ?? null) !== null) {
            return '/index.php?section=organization&tab=projects&project='
                . rawurlencode((string) $notification['reminder_project_id']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $notification
     */
    private function targetLabel(array $notification): ?string
    {
        if (($notification['reminder_task_id'] ?? null) !== null) {
            return 'Tarea: ' . (string) ($notification['task_title'] ?? 'sin titulo');
        }

        if (($notification['reminder_project_id'] ?? null) !== null) {
            return 'Proyecto: ' . (string) ($notification['project_title'] ?? 'sin titulo');
        }

        return null;
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            NotificationRepository::TYPE_REMINDER_DUE => 'Recordatorio',
            default => 'Notificacion',
        };
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            $id = (int) $value;
        } else {
            throw new TaskValidationException("Identificador invalido: {$field}.");
        }

        if ($id < 1) {
            throw new TaskValidationException("Identificador invalido: {$field}.");
        }

        return $id;
    }
}
