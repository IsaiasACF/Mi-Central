<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Friends\FriendRepository;
use Modules\Friends\FriendService;
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
$service = new FriendService(new FriendRepository(Connection::get()));

try {
    if ($method === 'GET') {
        $friendId = friendIdFromRequest();

        if ($friendId !== null) {
            $friend = $service->get($userId, $friendId);

            if ($friend === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Amigo no encontrado.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => $friend]);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->list($userId, $_GET)]);
    }

    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = friendPayload();

    if (!Csrf::validate(friendCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    if ($method === 'POST') {
        $friendId = friendIdFromRequest();
        $action = (string) ($_GET['action'] ?? $payload['action'] ?? '');

        if ($friendId === null) {
            if ($action !== '') {
                JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
            }

            JsonResponse::send(['ok' => true, 'data' => $service->create($userId, $payload)], 201);
        }

        if ($action === 'activate' || $action === 'deactivate') {
            $friend = $service->setActive($userId, $friendId, $action === 'activate');

            if ($friend === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Amigo no encontrado.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => $friend]);
        }

        JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
    }

    $friendId = friendIdFromRequest();

    if ($friendId === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Amigo requerido.'], 400);
    }

    if ($method === 'DELETE') {
        if (!$service->delete($userId, $friendId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Amigo no encontrado.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => ['deleted' => true]]);
    }

    $friend = $service->update($userId, $friendId, $payload);

    if ($friend === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Amigo no encontrado.'], 404);
    }

    JsonResponse::send(['ok' => true, 'data' => $friend]);
} catch (FriendValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Friends API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function friendIdFromRequest(): ?int
{
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        return null;
    }

    return (int) $_GET['id'];
}

/**
 * @return array<string, mixed>
 */
function friendPayload(): array
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
function friendCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
