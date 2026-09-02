<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Organization\LabelRepository;
use Modules\Organization\LabelService;
use Modules\Organization\ProjectRepository;
use Modules\Organization\ProjectService;
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
$pdo = Connection::get();
$labelService = new LabelService(new LabelRepository($pdo));
$service = new ProjectService(new ProjectRepository($pdo), (string) ($config['app']['timezone'] ?? 'America/Santiago'), $labelService);

try {
    if ($method === 'GET') {
        $projectId = projectIdFromRequest();

        if ($projectId !== null) {
            $project = $service->get($userId, $projectId);

            if ($project === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Proyecto no encontrado.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => $project]);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->list($userId, $_GET)]);
    }

    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = projectPayload();

    if (!Csrf::validate(projectCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    if ($method === 'POST') {
        $projectId = projectIdFromRequest();
        $action = (string) ($_GET['action'] ?? $payload['action'] ?? '');

        if ($projectId !== null && $action === 'complete') {
            $project = $service->complete($userId, $projectId);
            JsonResponse::send($project === null
                ? ['ok' => false, 'error' => 'Proyecto no encontrado.']
                : ['ok' => true, 'data' => $project], $project === null ? 404 : 200);
        }

        if ($projectId !== null && $action === 'archive') {
            $project = $service->archive($userId, $projectId);
            JsonResponse::send($project === null
                ? ['ok' => false, 'error' => 'Proyecto no encontrado.']
                : ['ok' => true, 'data' => $project], $project === null ? 404 : 200);
        }

        if ($projectId !== null) {
            JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->create($userId, $payload)], 201);
    }

    $projectId = projectIdFromRequest();

    if ($projectId === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Proyecto requerido.'], 400);
    }

    if ($method === 'DELETE') {
        if (!$service->delete($userId, $projectId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Proyecto no encontrado.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => ['deleted' => true]]);
    }

    $project = $service->update($userId, $projectId, $payload);

    if ($project === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Proyecto no encontrado.'], 404);
    }

    JsonResponse::send(['ok' => true, 'data' => $project]);
} catch (TaskValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Organization project API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function projectIdFromRequest(): ?int
{
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        return null;
    }

    return (int) $_GET['id'];
}

/**
 * @return array<string, mixed>
 */
function projectPayload(): array
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
function projectCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
