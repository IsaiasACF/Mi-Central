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
use Modules\Video\VideoStreamService;

$config = require dirname(__DIR__, 3) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    http_response_code(401);
    exit;
}

$jobId = videoExportStreamId();

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
$videoRepository = new VideoRepository($pdo);
$service = new VideoExportService(
    $videoRepository,
    new VideoEditorService($videoRepository, new VideoCutPointRepository($pdo), new VideoEditSegmentRepository($pdo)),
    $repository,
    $storage,
);

if ($service->isExpired($job)) {
    http_response_code(404);
    exit;
}

(new VideoStreamService($videoConfig, $storage))->streamExport(
    $job,
    is_string($_SERVER['HTTP_RANGE'] ?? null) ? (string) $_SERVER['HTTP_RANGE'] : null,
    is_string($_SERVER['REQUEST_METHOD'] ?? null) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET',
);

function videoExportStreamId(): ?int
{
    $id = $_GET['id'] ?? null;

    if (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
        return null;
    }

    return (int) $id;
}
