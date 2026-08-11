<?php
declare(strict_types=1);

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
        $videoId = videoSegmentsPositiveQueryId('video_id');

        if ($videoId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Video requerido.'], 400);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->listSegments($userId, $videoId)]);
    }

    if ($method !== 'POST') {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = videoSegmentsPayload();

    if (!Csrf::validate(videoSegmentsCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    $action = videoSegmentsAction($payload);

    if ($action === 'exclude' || $action === 'restore') {
        $segmentId = videoSegmentsPositiveQueryId('id');

        if ($segmentId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Segmento requerido.'], 400);
        }

        $collection = $service->setSegmentIncluded($userId, $segmentId, $action === 'restore');

        if ($collection === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Segmento no encontrado.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => $collection]);
    }

    if ($action === 'reorder') {
        $videoId = videoSegmentsPositiveQueryId('video_id');

        if ($videoId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Video requerido.'], 400);
        }

        $ids = $payload['segment_ids'] ?? null;

        if (!is_array($ids)) {
            JsonResponse::send(['ok' => false, 'error' => 'Orden de segmentos invalido.'], 422);
        }

        JsonResponse::send(['ok' => true, 'data' => $service->reorderSegments($userId, $videoId, $ids)]);
    }

    JsonResponse::send(['ok' => false, 'error' => 'Accion no permitida.'], 400);
} catch (VideoValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Video segments API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function videoSegmentsPositiveQueryId(string $key): ?int
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
function videoSegmentsPayload(): array
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
function videoSegmentsCsrfToken(array $payload): ?string
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
function videoSegmentsAction(array $payload): string
{
    $action = $_GET['action'] ?? $payload['action'] ?? '';

    return is_string($action) ? $action : '';
}
