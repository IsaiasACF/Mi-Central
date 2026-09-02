<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Organization\ReminderRepository;
use Modules\Organization\ReminderService;
use Modules\Organization\TaskValidationException;

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    JsonResponse::send(['ok' => false, 'error' => 'Autenticacion requerida.'], 401);
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$service = new ReminderService(
    new ReminderRepository(Connection::get()),
    (string) ($config['app']['timezone'] ?? 'America/Santiago'),
);

try {
    if ($method === 'GET') {
        $reminderId = reminderIdFromRequest();

        if ($reminderId !== null) {
            $reminder = $service->get($userId, $reminderId);

            if ($reminder === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Recordatorio no encontrado.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => $reminder]);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->list($userId, $_GET)]);
    }

    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = reminderPayload();

    if (!Csrf::validate(reminderCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    if ($method === 'POST') {
        $reminderId = reminderIdFromRequest();
        $action = (string) ($_GET['action'] ?? $payload['action'] ?? '');

        if ($reminderId !== null && $action === 'complete') {
            $reminder = $service->complete($userId, $reminderId);
            JsonResponse::send($reminder === null
                ? ['ok' => false, 'error' => 'Recordatorio no encontrado.']
                : ['ok' => true, 'data' => $reminder], $reminder === null ? 404 : 200);
        }

        if ($reminderId !== null && $action === 'dismiss') {
            $reminder = $service->dismiss($userId, $reminderId);
            JsonResponse::send($reminder === null
                ? ['ok' => false, 'error' => 'Recordatorio no encontrado.']
                : ['ok' => true, 'data' => $reminder], $reminder === null ? 404 : 200);
        }

        if ($reminderId !== null && $action === 'stop') {
            $reminder = $service->stopRecurrence($userId, $reminderId);
            JsonResponse::send($reminder === null
                ? ['ok' => false, 'error' => 'Recordatorio no encontrado.']
                : ['ok' => true, 'data' => $reminder], $reminder === null ? 404 : 200);
        }

        if ($reminderId !== null) {
            JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->create($userId, $payload)], 201);
    }

    $reminderId = reminderIdFromRequest();

    if ($reminderId === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Recordatorio requerido.'], 400);
    }

    if ($method === 'DELETE') {
        if (!$service->delete($userId, $reminderId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Recordatorio no encontrado.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => ['deleted' => true]]);
    }

    $reminder = $service->update($userId, $reminderId, $payload);

    if ($reminder === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Recordatorio no encontrado.'], 404);
    }

    JsonResponse::send(['ok' => true, 'data' => $reminder]);
} catch (TaskValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Organization reminder API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function reminderIdFromRequest(): ?int
{
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        return null;
    }

    return (int) $_GET['id'];
}

/**
 * @return array<string, mixed>
 */
function reminderPayload(): array
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
function reminderCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
