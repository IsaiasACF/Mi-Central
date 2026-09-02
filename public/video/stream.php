<?php
declare(strict_types=1);

$videoEnabled = filter_var(getenv('VIDEO_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN);

if ($videoEnabled === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Video no disponible en este entorno.');
}

use App\Database\Connection;
use App\Http\SecurityHeaders;
use App\Http\Session;
use Modules\Video\VideoRepository;
use Modules\Video\VideoStreamService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';

SecurityHeaders::send();
Session::start($config['app']['session']);

if (!Session::isAuthenticated()) {
    http_response_code(401);
    exit;
}

$user = Session::user();
$userId = (int) ($user['user_id'] ?? 0);
$videoId = videoStreamId();

if ($videoId === null) {
    http_response_code(404);
    exit;
}

$repository = new VideoRepository(Connection::get());
$video = $repository->findByIdForUser($userId, $videoId);

if ($video === null) {
    http_response_code(404);
    exit;
}

$streamer = new VideoStreamService(is_array($config['video'] ?? null) ? $config['video'] : []);
$streamer->stream(
    $video,
    is_string($_SERVER['HTTP_RANGE'] ?? null) ? (string) $_SERVER['HTTP_RANGE'] : null,
    is_string($_SERVER['REQUEST_METHOD'] ?? null) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET',
);

function videoStreamId(): ?int
{
    $id = $_GET['id'] ?? null;

    if (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
        return null;
    }

    return (int) $id;
}
