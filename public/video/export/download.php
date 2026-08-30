<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Video\VideoCutPointRepository;
use Modules\Video\VideoEditSegmentRepository;
use Modules\Video\VideoEditorService;
use Modules\Video\VideoExportJobRepository;
use Modules\Video\VideoExportService;
use Modules\Video\VideoRepository;
use Modules\Video\VideoStorage;

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    http_response_code(401);
    exit;
}

$jobId = videoExportDownloadId();

if ($jobId === null) {
    http_response_code(404);
    exit;
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$pdo = Connection::get();
$repository = new VideoExportJobRepository($pdo);
$job = $repository->findForUser($userId, $jobId);

if ($job === null || (string) ($job['status'] ?? '') !== 'completed') {
    http_response_code(404);
    exit;
}

$videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
$storage = new VideoStorage($videoConfig);
$service = new VideoExportService(
    new VideoRepository($pdo),
    new VideoEditorService(new VideoRepository($pdo), new VideoCutPointRepository($pdo), new VideoEditSegmentRepository($pdo)),
    $repository,
    $storage,
);

if ($service->isExpired($job)) {
    http_response_code(404);
    exit;
}

$path = $storage->pathForExportJob($job);

if ($path === null) {
    http_response_code(404);
    exit;
}

$size = filesize($path);

if (!is_int($size) || $size <= 0) {
    http_response_code(404);
    exit;
}

http_response_code(200);
header('Content-Type: video/mp4');
header('Content-Disposition: attachment; filename="' . addcslashes($service->safeDownloadName($job), "\\\"") . '"');
header('Content-Length: ' . $size);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$handle = fopen($path, 'rb');

if (!is_resource($handle)) {
    http_response_code(404);
    exit;
}

try {
    while (!feof($handle)) {
        $bytes = fread($handle, 8192);

        if (!is_string($bytes) || $bytes === '') {
            break;
        }

        echo $bytes;

        if (function_exists('flush')) {
            flush();
        }
    }
} finally {
    fclose($handle);
}

function videoExportDownloadId(): ?int
{
    $id = $_GET['id'] ?? null;

    if (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
        return null;
    }

    return (int) $id;
}
