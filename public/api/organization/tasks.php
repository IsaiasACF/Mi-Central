<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Organization\TaskRepository;
use Modules\Organization\TaskService;
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
$service = new TaskService(new TaskRepository(Connection::get()));

try {
    if ($method === 'GET') {
        $taskId = idFromRequest();

        if ($taskId !== null) {
            $task = $service->get($userId, $taskId);

            if ($task === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Tarea no encontrada.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => $task]);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->list($userId, $_GET)]);
    }

    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = requestPayload();

    if (!Csrf::validate(csrfTokenFromRequest($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    if ($method === 'POST') {
        $taskId = idFromRequest();
        $action = (string) ($_GET['action'] ?? $payload['action'] ?? '');

        if ($taskId !== null && $action === 'complete') {
            $task = $service->complete($userId, $taskId);
            JsonResponse::send($task === null
                ? ['ok' => false, 'error' => 'Tarea no encontrada.']
                : ['ok' => true, 'data' => $task], $task === null ? 404 : 200);
        }

        if ($taskId !== null && $action === 'reopen') {
            $task = $service->reopen($userId, $taskId);
            JsonResponse::send($task === null
                ? ['ok' => false, 'error' => 'Tarea no encontrada.']
                : ['ok' => true, 'data' => $task], $task === null ? 404 : 200);
        }

        if ($taskId !== null) {
            JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->create($userId, $payload)], 201);
    }

    $taskId = idFromRequest();

    if ($taskId === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Tarea requerida.'], 400);
    }

    if ($method === 'DELETE') {
        if (!$service->delete($userId, $taskId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Tarea no encontrada.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => ['deleted' => true]]);
    }

    $task = $service->update($userId, $taskId, $payload);

    if ($task === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Tarea no encontrada.'], 404);
    }

    JsonResponse::send(['ok' => true, 'data' => $task]);
} catch (TaskValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Organization task API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function idFromRequest(): ?int
{
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        return null;
    }

    return (int) $_GET['id'];
}

/**
 * @return array<string, mixed>
 */
function requestPayload(): array
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
function csrfTokenFromRequest(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
