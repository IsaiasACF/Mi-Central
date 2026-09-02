<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Notifications\NotificationRepository;
use Modules\Notifications\NotificationService;
use Modules\Organization\TaskValidationException;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    JsonResponse::send(['ok' => false, 'error' => 'Autenticacion requerida.'], 401);
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$service = new NotificationService(
    new NotificationRepository(Connection::get()),
    (string) ($config['app']['timezone'] ?? 'America/Santiago'),
);

try {
    if ($method === 'GET') {
        JsonResponse::send([
            'ok' => true,
            'data' => [
                'items' => $service->list($userId, $_GET),
                'unread_count' => $service->unreadCount($userId),
            ],
        ]);
    }

    if ($method !== 'POST') {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = notificationPayload();

    if (!Csrf::validate(notificationCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    $action = (string) ($_GET['action'] ?? $payload['action'] ?? '');

    if ($action === 'read-all') {
        JsonResponse::send([
            'ok' => true,
            'data' => [
                'updated' => $service->markAllRead($userId),
                'unread_count' => $service->unreadCount($userId),
            ],
        ]);
    }

    if ($action === 'read') {
        $notificationId = notificationIdFromRequest($payload);

        if ($notificationId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Notificacion requerida.'], 400);
        }

        $updated = $service->markRead($userId, $notificationId);
        JsonResponse::send($updated
            ? ['ok' => true, 'data' => ['updated' => true, 'unread_count' => $service->unreadCount($userId)]]
            : ['ok' => false, 'error' => 'Notificacion no encontrada.'], $updated ? 200 : 404);
    }

    JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
} catch (TaskValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Notifications API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

/**
 * @return array<string, mixed>
 */
function notificationPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input');

    if (is_string($raw) && $raw !== '' && str_contains($contentType, 'application/json')) {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

/**
 * @param array<string, mixed> $payload
 */
function notificationCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}

/**
 * @param array<string, mixed> $payload
 */
function notificationIdFromRequest(array $payload): ?int
{
    $value = $_GET['id'] ?? $payload['id'] ?? null;

    if ($value === null || $value === '') {
        return null;
    }

    return (int) $value;
}
