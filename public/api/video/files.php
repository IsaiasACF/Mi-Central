<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Video\VideoRepository;
use Modules\Video\VideoService;
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
$service = new VideoService(
    new VideoRepository(Connection::get()),
    is_array($config['video'] ?? null) ? $config['video'] : [],
);

try {
    if ($method === 'GET') {
        $videoId = videoIdFromRequest();

        if ($videoId !== null) {
            $video = $service->get($userId, $videoId);

            if ($video === null) {
                JsonResponse::send(['ok' => false, 'error' => 'Video no encontrado.'], 404);
            }

            JsonResponse::send(['ok' => true, 'data' => videoResource($video)]);
        }

        JsonResponse::send(['ok' => true, 'data' => videoResources($service->list($userId))]);
    }

    if (!in_array($method, ['POST', 'DELETE'], true)) {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    if ($method === 'POST' && $_POST === [] && $_FILES === [] && videoContentLength() > videoMaxUploadBytes($service)) {
        JsonResponse::send(['ok' => false, 'error' => 'El archivo supera el limite de ' . videoMaxUploadLabel($service) . '.'], 422);
    }

    $payload = videoPayload();

    if (!Csrf::validate(videoCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    if ($method === 'POST' && videoActionFromRequest($payload) === 'retry-metadata') {
        $videoId = videoIdFromRequest();

        if ($videoId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Video requerido.'], 400);
        }

        $video = $service->retryMetadata($userId, $videoId);

        if ($video === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Video no encontrado.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => videoResource($video)]);
    }

    if ($method === 'POST' && videoActionFromRequest($payload) === 'delete') {
        $videoId = videoIdFromRequest();

        if ($videoId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Video requerido.'], 400);
        }

        if (!$service->delete($userId, $videoId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Video no encontrado.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => ['deleted' => true, 'message' => 'Video eliminado correctamente.']]);
    }

    if ($method === 'POST') {
        $file = videoUploadedFileFromRequest();

        if (!is_array($file)) {
            $error = $_FILES === [] ? 'Selecciona un video para subir.' : 'No se pudo recibir el video seleccionado.';
            JsonResponse::send(['ok' => false, 'error' => $error], 422);
        }

        JsonResponse::send(['ok' => true, 'data' => videoResource($service->upload($userId, $file))], 201);
    }

    $videoId = videoIdFromRequest();

    if ($videoId === null) {
        JsonResponse::send(['ok' => false, 'error' => 'Video requerido.'], 400);
    }

    if (!$service->delete($userId, $videoId)) {
        JsonResponse::send(['ok' => false, 'error' => 'Video no encontrado.'], 404);
    }

    JsonResponse::send(['ok' => true, 'data' => ['deleted' => true, 'message' => 'Video eliminado correctamente.']]);
} catch (VideoValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Video files API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

/**
 * @param array<string, mixed> $payload
 */
function videoActionFromRequest(array $payload): string
{
    $action = $_GET['action'] ?? $payload['action'] ?? '';

    return is_string($action) ? $action : '';
}

function videoIdFromRequest(): ?int
{
    if (!isset($_GET['id']) || $_GET['id'] === '') {
        return null;
    }

    return (int) $_GET['id'];
}

/**
 * @return array<string, mixed>|null
 */
function videoUploadedFileFromRequest(): ?array
{
    if (isset($_FILES['video']) && is_array($_FILES['video'])) {
        return $_FILES['video'];
    }

    if ($_FILES !== []) {
        $fields = implode(',', array_keys($_FILES));
        error_log('Video upload missing expected file field "video". Received fields: ' . $fields);
    }

    return null;
}

/**
 * @param array<int, array<string, mixed>> $videos
 * @return array<int, array<string, mixed>>
 */
function videoResources(array $videos): array
{
    return array_map(static fn (array $video): array => videoResource($video), $videos);
}

/**
 * @param array<string, mixed> $video
 * @return array<string, mixed>
 */
function videoResource(array $video): array
{
    return [
        'id' => (int) ($video['id'] ?? 0),
        'original_name' => (string) ($video['original_name'] ?? ''),
        'extension' => (string) ($video['extension'] ?? ''),
        'mime_type' => (string) ($video['mime_type'] ?? ''),
        'size_bytes' => (int) ($video['size_bytes'] ?? 0),
        'status' => (string) ($video['status'] ?? 'pending_metadata'),
        'duration_seconds' => ($video['duration_seconds'] ?? null) === null ? null : (float) $video['duration_seconds'],
        'width' => ($video['width'] ?? null) === null ? null : (int) $video['width'],
        'height' => ($video['height'] ?? null) === null ? null : (int) $video['height'],
        'fps' => ($video['fps'] ?? null) === null ? null : (float) $video['fps'],
        'video_codec' => ($video['video_codec'] ?? null) === null ? null : (string) $video['video_codec'],
        'audio_codec' => ($video['audio_codec'] ?? null) === null ? null : (string) $video['audio_codec'],
        'container_format' => ($video['container_format'] ?? null) === null ? null : (string) $video['container_format'],
        'bitrate' => ($video['bitrate'] ?? null) === null ? null : (int) $video['bitrate'],
        'metadata_status' => (string) ($video['metadata_status'] ?? 'pending'),
        'analyzed_at' => ($video['analyzed_at'] ?? null) === null ? null : (string) $video['analyzed_at'],
        'created_at' => (string) ($video['created_at'] ?? ''),
        'updated_at' => (string) ($video['updated_at'] ?? ''),
    ];
}

function videoContentLength(): int
{
    $value = $_SERVER['CONTENT_LENGTH'] ?? '0';

    return is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1 ? (int) $value : 0;
}

function videoMaxUploadBytes(VideoService $service): int
{
    return $service->maxUploadMb() * 1024 * 1024;
}

function videoMaxUploadLabel(VideoService $service): string
{
    $mb = $service->maxUploadMb();

    if ($mb >= 1024 && $mb % 1024 === 0) {
        return (int) ($mb / 1024) . ' GB';
    }

    return $mb . ' MB';
}

/**
 * @return array<string, mixed>
 */
function videoPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (str_contains($contentType, 'application/json')) {
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
function videoCsrfToken(array $payload): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (is_string($header) && $header !== '') {
        return $header;
    }

    $token = $payload['csrf_token'] ?? null;

    return is_string($token) ? $token : null;
}
