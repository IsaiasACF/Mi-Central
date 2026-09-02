<?php
declare(strict_types=1);

$videoEnabled = filter_var(getenv('VIDEO_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN);

if ($videoEnabled === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Video no disponible en este entorno.');
}

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Video\VideoCutPointRepository;
use Modules\Video\VideoEditSegmentRepository;
use Modules\Video\VideoEditorService;
use Modules\Video\VideoRepository;
use Modules\Video\VideoValidationException;

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
$service = new VideoEditorService(
    new VideoRepository($pdo),
    new VideoCutPointRepository($pdo),
    new VideoEditSegmentRepository($pdo),
);

try {
    if ($method === 'GET') {
        $videoId = videoCutsPositiveQueryId('video_id');

        if ($videoId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Video requerido.'], 400);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->list($userId, $videoId)]);
    }

    if ($method !== 'POST') {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = videoCutsPayload();

    if (!Csrf::validate(videoCutsCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    $action = videoCutsAction($payload);

    if ($action === 'create') {
        $videoId = videoCutsPositiveQueryId('video_id');

        if ($videoId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Video requerido.'], 400);
        }

        JsonResponse::send([
            'ok' => true,
            'data' => [
                'cut_point' => $service->create($userId, $videoId, $payload['position_seconds'] ?? null),
                'cut_points' => $service->list($userId, $videoId),
                'segments' => $service->listSegments($userId, $videoId),
            ],
        ], 201);
    }

    if ($action === 'update') {
        $cutPointId = videoCutsPositiveQueryId('id');

        if ($cutPointId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Punto de corte requerido.'], 400);
        }

        $cutPoint = $service->update($userId, $cutPointId, $payload['position_seconds'] ?? null);

        if ($cutPoint === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Punto de corte no encontrado.'], 404);
        }

        JsonResponse::send([
            'ok' => true,
            'data' => [
                'cut_point' => $cutPoint,
                'cut_points' => $service->list($userId, (int) $cutPoint['video_id']),
                'segments' => $service->listSegments($userId, (int) $cutPoint['video_id']),
            ],
        ]);
    }

    if ($action === 'delete') {
        $cutPointId = videoCutsPositiveQueryId('id');
        $videoId = videoCutsPositiveQueryId('video_id');

        if ($cutPointId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Punto de corte requerido.'], 400);
        }

        $deleted = $service->delete($userId, $cutPointId);

        if (!$deleted) {
            JsonResponse::send(['ok' => false, 'error' => 'Punto de corte no encontrado.'], 404);
        }

        JsonResponse::send([
            'ok' => true,
            'data' => [
                'deleted' => true,
                'cut_points' => $videoId === null ? [] : $service->list($userId, $videoId),
                'segments' => $videoId === null ? null : $service->listSegments($userId, $videoId),
            ],
        ]);
    }

    JsonResponse::send(['ok' => false, 'error' => 'Accion no permitida.'], 400);
} catch (VideoValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Video cuts API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function videoCutsPositiveQueryId(string $key): ?int
{
    $value = $_GET[$key] ?? null;

    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        return null;
    }

    return (int) $value;
}

/**
 * @return array<string, mixed>
 */
function videoCutsPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (is_string($contentType) && str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

/**
 * @param array<string, mixed> $payload
 */
function videoCutsCsrfToken(array $payload): ?string
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
function videoCutsAction(array $payload): string
{
    $action = $_GET['action'] ?? $payload['action'] ?? '';

    return is_string($action) ? $action : '';
}
