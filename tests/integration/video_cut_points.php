<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Video\VideoCutPointRepository;
use Modules\Video\VideoEditSegmentRepository;
use Modules\Video\VideoEditorService;
use Modules\Video\VideoRepository;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
$videoRepository = new VideoRepository($pdo);
$cutRepository = new VideoCutPointRepository($pdo);
$segmentRepository = new VideoEditSegmentRepository($pdo);
$editorService = new VideoEditorService($videoRepository, $cutRepository, $segmentRepository);
$username = 'test_video_cuts_' . bin2hex(random_bytes(4));
$otherUsername = 'test_video_cuts_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-cuts-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-cuts-other-');
$createdFiles = [];
$exitCode = 1;

function video_cuts_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function video_cuts_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
{
    $handle = curl_init($url);
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        throw new RuntimeException('Could not encode request payload.');
    }

    $requestHeaders = $headers;

    if ($body !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);

    if ($cookieFile !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }

    if ($requestHeaders !== []) {
        curl_setopt($handle, CURLOPT_HTTPHEADER, $requestHeaders);
    }

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $responseBody = substr($response, $headerSize);
    $json = json_decode($responseBody, true);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => $responseBody,
        'json' => is_array($json) ? $json : null,
    ];
}

function video_cuts_form_request(string $url, array $postFields, string $cookieFile): array
{
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);

    $response = curl_exec($handle);

    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function video_cuts_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function video_cuts_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function video_cuts_login(string $username, string $password, string $cookieFile): void
{
    $loginPage = video_cuts_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = video_cuts_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => video_cuts_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    video_cuts_assert($login['status'] === 302, 'Test user could not log in.');
}

function video_cuts_uploads_path(array $videoConfig): string
{
    $path = $videoConfig['uploads_path'] ?? null;

    return is_string($path) && $path !== '' ? rtrim($path, '/') : dirname(__DIR__, 2) . '/storage/video/uploads';
}

function video_cuts_create_video(VideoRepository $repository, int $userId, array $videoConfig, string $name, string $contents, ?float $duration = 300.0): array
{
    $storedName = bin2hex(random_bytes(16)) . '.mp4';
    $path = video_cuts_uploads_path($videoConfig) . '/' . $storedName;
    file_put_contents($path, $contents);
    $id = $repository->create($userId, [
        'original_name' => $name,
        'stored_name' => $storedName,
        'storage_path' => 'storage/video/uploads/' . $storedName,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => strlen($contents),
        'status' => 'pending_metadata',
        'metadata_status' => $duration === null ? 'pending' : 'ready',
    ]);

    if ($duration !== null) {
        $statement = Connection::get()->prepare(
            "UPDATE video_files
             SET duration_seconds = :duration_seconds,
                 width = 1280,
                 height = 720,
                 fps = 30,
                 video_codec = 'h264',
                 audio_codec = 'aac',
                 container_format = 'mov,mp4',
                 metadata_status = 'ready',
                 analyzed_at = UTC_TIMESTAMP()
             WHERE id = :id"
        );
        $statement->execute([
            'duration_seconds' => number_format($duration, 3, '.', ''),
            'id' => $id,
        ]);
    }

    return [$id, $path];
}

function video_cuts_table_exists(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute(['table' => 'video_cut_points']);

    return (int) $statement->fetchColumn() === 1;
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080009_create_video_files.php',
        '202608080010_add_video_file_metadata.php',
        '202608080011_create_video_cut_points.php',
        '202608080012_create_video_edit_segments.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    video_cuts_assert(video_cuts_table_exists($pdo), 'video_cut_points table was not created.');

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    [$videoId, $path] = video_cuts_create_video($videoRepository, $userId, $videoConfig, 'cuts-own.mp4', 'abcdefghijklmnopqrstuvwxyz0123456789', 300.0);
    $createdFiles[] = $path;
    [$otherVideoId, $otherPath] = video_cuts_create_video($videoRepository, $otherUserId, $videoConfig, 'cuts-other.mp4', 'other-original-bytes', 300.0);
    $createdFiles[] = $otherPath;
    [$pendingVideoId, $pendingPath] = video_cuts_create_video($videoRepository, $userId, $videoConfig, 'cuts-pending.mp4', 'pending-original-bytes', null);
    $createdFiles[] = $pendingPath;

    $originalHash = hash_file('sha256', $path);
    $originalSize = filesize($path);

    video_cuts_login($username, $password, $cookieFile);
    video_cuts_login($otherUsername, $otherPassword, $otherCookieFile);

    $detail = video_cuts_request('http://127.0.0.1/index.php?section=video-editor&id=' . $videoId, 'GET', null, $cookieFile);
    video_cuts_assert($detail['status'] === 200 && str_contains($detail['body'], 'Editar video'), 'Detail did not expose editor action.');
    $csrf = video_cuts_screen_csrf($detail['body']);

    $editor = video_cuts_request('http://127.0.0.1/index.php?section=video-editor&id=' . $videoId . '&editor=1', 'GET', null, $cookieFile);
    video_cuts_assert($editor['status'] === 200 && str_contains($editor['body'], 'data-video-editor') && str_contains($editor['body'], 'data-video-timeline') && str_contains($editor['body'], 'Aun no has agregado puntos de corte.'), 'Editor timeline did not render.');

    $blocked = video_cuts_request('http://127.0.0.1/index.php?section=video-editor&id=' . $pendingVideoId . '&editor=1', 'GET', null, $cookieFile);
    video_cuts_assert($blocked['status'] === 200 && str_contains($blocked['body'], 'El video debe terminar de analizarse antes de abrir el editor.'), 'Video without duration was editable.');

    $missingVideo = video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=999999999&action=create', 'POST', ['action' => 'create', 'position_seconds' => 1], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_assert($missingVideo['status'] === 422, 'Missing video did not fail safely.');

    $noCsrf = video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 60], $cookieFile);
    video_cuts_assert($noCsrf['status'] === 403, 'Creating a cut did not require CSRF.');

    $zero = video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 0], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_assert($zero['status'] === 422 && str_contains($zero['json']['error'] ?? '', 'fuera del video'), 'Cut at 0 was not rejected.');

    $atEnd = video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 300], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_assert($atEnd['status'] === 422, 'Cut at duration was not rejected.');

    $create60 = video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 60], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_assert($create60['status'] === 201 && (int) ($create60['json']['data']['cut_point']['id'] ?? 0) > 0, 'Valid cut was not created.');
    $cut60Id = (int) $create60['json']['data']['cut_point']['id'];

    $duplicate = video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 60.03], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_assert($duplicate['status'] === 422 && str_contains($duplicate['json']['error'] ?? '', 'proximo'), 'Nearby duplicate cut was not rejected.');

    video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 240], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 150], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    $list = video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId, 'GET', null, $cookieFile);
    $positions = array_map(static fn (array $cut): float => (float) $cut['position_seconds'], $list['json']['data'] ?? []);
    video_cuts_assert($positions === [60.0, 150.0, 240.0], 'Cut points were not ordered.');

    $update = video_cuts_request('http://127.0.0.1/api/video/cuts.php?id=' . $cut60Id . '&action=update', 'POST', ['action' => 'update', 'position_seconds' => 45.2], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_assert($update['status'] === 200 && abs((float) ($update['json']['data']['cut_point']['position_seconds'] ?? 0) - 45.2) < 0.001, 'Cut point was not updated.');

    $foreignCreate = video_cuts_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $otherVideoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 10], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_assert($foreignCreate['status'] === 422, 'User created cut on another user video.');

    $delete = video_cuts_request('http://127.0.0.1/api/video/cuts.php?id=' . $cut60Id . '&video_id=' . $videoId . '&action=delete', 'POST', ['action' => 'delete'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_assert($delete['status'] === 200 && ($delete['json']['data']['deleted'] ?? false) === true, 'Cut point was not deleted.');

    $deleteAgain = video_cuts_request('http://127.0.0.1/api/video/cuts.php?id=' . $cut60Id . '&video_id=' . $videoId . '&action=delete', 'POST', ['action' => 'delete'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_cuts_assert($deleteAgain['status'] === 404, 'Deleting an already removed cut did not return a safe error.');

    $segments = $editorService->virtualSegments(300.0, [60.0, 150.0, 240.0]);
    video_cuts_assert($segments === [
        ['start' => 0.0, 'end' => 60.0],
        ['start' => 60.0, 'end' => 150.0],
        ['start' => 150.0, 'end' => 240.0],
        ['start' => 240.0, 'end' => 300.0],
    ], 'Virtual segment derivation is wrong.');

    clearstatcache(true, $path);
    video_cuts_assert(is_file($path) && hash_file('sha256', $path) === $originalHash && filesize($path) === $originalSize, 'Original file changed after cut point operations.');

    foreach (['public/api/video/cuts.php', 'modules/Video/VideoEditorService.php', 'modules/Video/VideoCutPointRepository.php'] as $sourcePath) {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $sourcePath);
        video_cuts_assert(is_string($source) && !str_contains(strtolower($source), 'ffmpeg') && !str_contains(strtolower($source), 'ffprobe') && !str_contains($source, 'proc_open'), 'Editor cut operations reference media processing.');
    }

    $range = video_cuts_request('http://127.0.0.1/video/stream.php?id=' . $videoId, 'GET', null, $cookieFile, ['Range: bytes=2-5']);
    video_cuts_assert($range['status'] === 206 && $range['body'] === 'cdef', 'Streaming Range broke after cut point operations.');

    $editorJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    video_cuts_assert(is_string($editorJs) && str_contains($editorJs, 'secondsToTimecode') && str_contains($editorJs, 'timecodeToSeconds') && str_contains($editorJs, "event.key.toLowerCase() === 'c'") && str_contains($editorJs, 'video.currentTime'), 'Editor JS helpers or shortcuts are missing.');

    echo "Video cut points: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video cut points: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ($createdFiles as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    foreach ([$cookieFile, $otherCookieFile] as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);
    $statement = $pdo->prepare('DELETE FROM users WHERE username IN (:username, :other_username)');
    $statement->execute([
        'username' => $username,
        'other_username' => $otherUsername,
    ]);
}

exit($exitCode);
