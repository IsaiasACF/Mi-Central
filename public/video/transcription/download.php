<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Video\TranscriptionExportService;
use Modules\Video\VideoTranscriptionRepository;
use Modules\Video\VideoValidationException;

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    http_response_code(401);
    exit;
}

$transcriptionId = videoTranscriptionDownloadId();
$format = videoTranscriptionDownloadFormat();

if ($transcriptionId === null || $format === null) {
    http_response_code(404);
    exit;
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$service = new TranscriptionExportService(new VideoTranscriptionRepository(Connection::get()));

try {
    $export = $service->export($userId, $transcriptionId, $format);
} catch (VideoValidationException $exception) {
    $message = $exception->getMessage();
    http_response_code(str_contains($message, 'Formato') || str_contains($message, 'completada') || str_contains($message, 'segmentos') || str_contains($message, 'texto') ? 422 : 404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
} catch (Throwable) {
    error_log('Video transcription download failed.');
    http_response_code(500);
    exit;
}

$content = $export['content'];

http_response_code(200);
header('Content-Type: ' . $export['content_type']);
header('Content-Disposition: attachment; filename="' . addcslashes($export['filename'], "\\\"") . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

echo $content;

function videoTranscriptionDownloadId(): ?int
{
    $id = $_GET['id'] ?? null;

    if (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
        return null;
    }

    return (int) $id;
}

function videoTranscriptionDownloadFormat(): ?string
{
    $format = $_GET['format'] ?? null;

    if (!is_string($format) || preg_match('/\A[A-Za-z0-9_]+\z/', $format) !== 1) {
        return null;
    }

    return $format;
}
