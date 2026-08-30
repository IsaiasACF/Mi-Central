<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Organization\LabelRepository;
use Modules\Organization\LabelService;
use Modules\Organization\NoteRepository;
use Modules\Organization\NoteService;
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
$service = new NoteService(new NoteRepository($pdo), $labelService);

try {
    if ($method === 'GET') {
        $noteId = noteIdFromRequest();

        if ($noteId !== null) {
            $note = $service->get($userId, $noteId);

            if ($note === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Nota no encontrada.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => $note]);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->list($userId, $_GET)]);
    }

    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = notePayload();

    if (!Csrf::validate(noteCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    if ($method === 'POST') {
        $noteId = noteIdFromRequest();

        if ($noteId !== null) {
            $action = noteActionFromRequest();

            if (!in_array($action, ['complete', 'reopen'], true)) {
                JsonResponse::send(['ok' => false, 'error' => 'Accion invalida.'], 400);
            }

            $note = $action === 'complete'
                ? $service->complete($userId, $noteId)
                : $service->reopen($userId, $noteId);

            if ($note === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Nota no encontrada.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => $note]);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->create($userId, $payload)], 201);
    }

    $noteId = noteIdFromRequest();

    if ($noteId === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Nota requerida.'], 400);
    }

    if ($method === 'DELETE') {
        if (!$service->delete($userId, $noteId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Nota no encontrada.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => ['deleted' => true]]);
    }

    $note = $service->update($userId, $noteId, $payload);

    if ($note === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Nota no encontrada.'], 404);
    }

    JsonResponse::send(['ok' => true, 'data' => $note]);
} catch (TaskValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Organization note API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function noteIdFromRequest(): ?int
{
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        return null;
    }

    return (int) $_GET['id'];
}

function noteActionFromRequest(): string
{
    return is_string($_GET['action'] ?? null) ? (string) $_GET['action'] : '';
}

/**
 * @return array<string, mixed>
 */
function notePayload(): array
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
function noteCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
