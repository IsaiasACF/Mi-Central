<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\Csrf;
use App\Http\JsonResponse;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Video\TranscriptionSourceResolver;
use Modules\Video\VideoExportJobRepository;
use Modules\Video\VideoRepository;
use Modules\Video\VideoStorage;
use Modules\Video\VideoTranscriptionRepository;
use Modules\Video\VideoTranscriptionService;
use Modules\Video\VideoValidationException;
use Modules\Video\WhisperService;

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
$videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
$storage = new VideoStorage($videoConfig);
$service = new VideoTranscriptionService(
    new VideoTranscriptionRepository($pdo),
    new TranscriptionSourceResolver(new VideoRepository($pdo), new VideoExportJobRepository($pdo), $storage),
    new WhisperService($videoConfig, $storage),
);

try {
    if ($method === 'GET') {
        $videoId = videoTranscriptionsPositiveQueryId('video_id');

        if ($videoId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Video requerido.'], 400);
        }

        JsonResponse::send(['ok' => true, 'data' => [
            'items' => $service->list($userId, $videoId),
            'model' => $service->modelResource()['model'],
        ]]);
    }

    if ($method !== 'POST') {
        JsonResponse::send(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    }

    $payload = videoTranscriptionsPayload();

    if (!Csrf::validate(videoTranscriptionsCsrfToken($payload))) {
        JsonResponse::send(['ok' => false, 'error' => 'Solicitud invalida.'], 403);
    }

    $action = videoTranscriptionsAction($payload);

    if ($action === 'create') {
        $videoId = videoTranscriptionsPositiveQueryId('video_id');
        $exportJobId = videoTranscriptionsPositiveQueryId('export_job_id');

        if (($videoId === null && $exportJobId === null) || ($videoId !== null && $exportJobId !== null)) {
            JsonResponse::send(['ok' => false, 'error' => 'Fuente de transcripcion requerida.'], 400);
        }

        $data = $exportJobId !== null
            ? $service->createForExport($userId, $exportJobId, $payload['requested_language'] ?? 'auto')
            : $service->createForVideo($userId, (int) $videoId, $payload['requested_language'] ?? 'auto');

        JsonResponse::send(['ok' => true, 'data' => $data], 201);
    }

    if ($action === 'delete') {
        $transcriptionId = videoTranscriptionsPositiveQueryId('id');

        if ($transcriptionId === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Transcripcion requerida.'], 400);
        }

        if (!$service->delete($userId, $transcriptionId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Transcripcion no encontrada.'], 404);
        }

        JsonResponse::send(['ok' => true, 'data' => ['deleted' => true]]);
    }

    JsonResponse::send(['ok' => false, 'error' => 'Accion no permitida.'], 400);
} catch (VideoValidationException $exception) {
    JsonResponse::send(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable) {
    error_log('Video transcriptions API failed.');
    JsonResponse::send(['ok' => false, 'error' => 'No se pudo procesar la solicitud.'], 500);
}

function videoTranscriptionsPositiveQueryId(string $key): ?int
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
function videoTranscriptionsPayload(): array
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
function videoTranscriptionsCsrfToken(array $payload): ?string
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
function videoTranscriptionsAction(array $payload): string
{
    $action = $_GET['action'] ?? $payload['action'] ?? '';

    return is_string($action) ? $action : '';
}
