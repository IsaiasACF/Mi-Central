<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Friends\FriendScheduleRepository;
use Modules\Friends\FriendScheduleService;
use Modules\Friends\FriendValidationException;

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    JsonResponse::send(['ok' => false, 'error' => 'Autenticacion requerida.'], 401);
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$service = new FriendScheduleService(new FriendScheduleRepository(Connection::get()));

try {
    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? '');

        if ($action === 'exceptions') {
            JsonResponse::send([
                'ok' => true,
                'data' => $service->listExceptions(
                    $userId,
                    scheduleTargetType($_GET),
                    scheduleFriendId($_GET)
                ),
            ]);
        }

        JsonResponse::send([
            'ok' => true,
            'data' => $service->listEntries(
                $userId,
                scheduleTargetType($_GET),
                scheduleFriendId($_GET),
                $_GET
            ),
        ]);
    }

    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = schedulePayload();

    if (!Csrf::validate(scheduleCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    $targetType = scheduleTargetType(array_merge($_GET, $payload));
    $friendId = scheduleFriendId(array_merge($_GET, $payload));
    $action = (string) ($_GET['action'] ?? $payload['action'] ?? '');

    if ($method === 'POST' && ($action === 'import-preview' || $action === 'import')) {
        $json = $payload['json'] ?? '';

        if (!is_string($json)) {
            JsonResponse::send(['ok' => false, 'error' => 'JSON requerido.'], 422);
        }

        $result = $action === 'import-preview'
            ? $service->previewImport($userId, $targetType, $friendId, $json)
            : $service->importJson($userId, $targetType, $friendId, $json);

        $status = ($result['errors'] ?? []) === [] ? 200 : 422;
        JsonResponse::send(['ok' => $status === 200, 'data' => $result, 'error' => $status === 200 ? null : 'Importacion invalida.'], $status);
    }

    if ($action === 'exceptions') {
        if ($method === 'POST') {
            if (scheduleIdFromRequest() !== null) {
                JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
            }

            JsonResponse::send([
                'ok' => true,
                'data' => $service->createException($userId, $targetType, $friendId, $payload),
            ], 201);
        }

        $exceptionId = scheduleIdFromRequest();

        if ($exceptionId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Excepcion requerida.'], 400);
        }

        if ($method === 'DELETE') {
            if (!$service->deleteException($userId, $targetType, $exceptionId)) {
                JsonResponse::send(['ok' => false, 'error' => 'Excepcion no encontrada.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => ['deleted' => true]]);
        }

        $exception = $service->updateException($userId, $targetType, $exceptionId, $payload, $friendId);

        if ($exception === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Excepcion no encontrada.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => $exception]);
    }

    if ($method === 'POST') {
        if (scheduleIdFromRequest() !== null) {
            JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
        }

        JsonResponse::send([
            'ok' => true,
            'data' => $service->createEntry($userId, $targetType, $friendId, $payload),
        ], 201);
    }

    $entryId = scheduleIdFromRequest();

    if ($entryId === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Bloque requerido.'], 400);
    }

    if ($method === 'DELETE') {
        if (!$service->deleteEntry($userId, $targetType, $entryId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Bloque no encontrado.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => ['deleted' => true]]);
    }

    $entry = $service->updateEntry($userId, $targetType, $entryId, $payload, $friendId);

    if ($entry === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Bloque no encontrado.'], 404);
    }

    JsonResponse::send(['ok' => true, 'data' => $entry]);
} catch (FriendValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Friends schedule API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function scheduleIdFromRequest(): ?int
{
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        return null;
    }

    return (int) $_GET['id'];
}

/**
 * @param array<string, mixed> $source
 */
function scheduleTargetType(array $source): string
{
    $targetType = $source['target_type'] ?? 'user';

    return is_string($targetType) ? $targetType : 'user';
}

/**
 * @param array<string, mixed> $source
 */
function scheduleFriendId(array $source): ?int
{
    $friendId = $source['friend_id'] ?? null;

    if ($friendId === null || $friendId === '') {
        return null;
    }

    return (int) $friendId;
}

/**
 * @return array<string, mixed>
 */
function schedulePayload(): array
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
function scheduleCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
