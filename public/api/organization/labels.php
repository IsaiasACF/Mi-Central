<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Organization\LabelRepository;
use Modules\Organization\LabelService;
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
$service = new LabelService(new LabelRepository(Connection::get()));

try {
    if ($method === 'GET') {
        $labelId = labelIdFromRequest();

        if ($labelId !== null) {
            $label = $service->get($userId, $labelId);

            if ($label === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Etiqueta no encontrada.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => $label]);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->list($userId)]);
    }

    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = labelPayload();

    if (!Csrf::validate(labelCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    if ($method === 'POST') {
        if (labelIdFromRequest() !== null) {
            JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->create($userId, $payload)], 201);
    }

    $labelId = labelIdFromRequest();

    if ($labelId === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Etiqueta requerida.'], 400);
    }

    if ($method === 'DELETE') {
        if (!$service->delete($userId, $labelId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Etiqueta no encontrada.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => ['deleted' => true]]);
    }

    $label = $service->update($userId, $labelId, $payload);

    if ($label === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Etiqueta no encontrada.'], 404);
    }

    JsonResponse::send(['ok' => true, 'data' => $label]);
} catch (TaskValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Organization label API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function labelIdFromRequest(): ?int
{
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        return null;
    }

    return (int) $_GET['id'];
}

/**
 * @return array<string, mixed>
 */
function labelPayload(): array
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
function labelCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
