<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Video\VideoRepository;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
$repository = new VideoRepository($pdo);
$username = 'test_video_stream_' . bin2hex(random_bytes(4));
$otherUsername = 'test_video_stream_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-stream-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-stream-other-');
$createdFiles = [];
$exitCode = 1;

function video_stream_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function video_stream_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function video_stream_form_request(string $url, array $postFields, string $cookieFile): array
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

function video_stream_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function video_stream_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function video_stream_login(string $username, string $password, string $cookieFile): void
{
    $loginPage = video_stream_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = video_stream_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => video_stream_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    video_stream_assert($login['status'] === 302, 'Test user could not log in.');
}

function video_stream_uploads_path(array $videoConfig): string
{
    $path = $videoConfig['uploads_path'] ?? null;

    return is_string($path) && $path !== '' ? rtrim($path, '/') : dirname(__DIR__, 2) . '/storage/video/uploads';
}

function video_stream_create_file_video(VideoRepository $repository, int $userId, array $videoConfig, string $name, string $contents, array $metadata = []): array
{
    $storedName = bin2hex(random_bytes(16)) . '.mp4';
    $path = video_stream_uploads_path($videoConfig) . '/' . $storedName;
    file_put_contents($path, $contents);
    $id = $repository->create($userId, array_merge([
        'original_name' => $name,
        'stored_name' => $storedName,
        'storage_path' => 'storage/video/uploads/' . $storedName,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => strlen($contents),
        'status' => 'pending_metadata',
    ], $metadata));

    return [$id, $path, $storedName];
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080009_create_video_files.php',
        '202608080010_add_video_file_metadata.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);

    [$videoId, $path] = video_stream_create_file_video($repository, $userId, $videoConfig, 'Reunion Ellison Parte 2.mp4', 'abcdefghijklmnopqrstuvwxyz', ['metadata_status' => 'ready']);
    $createdFiles[] = $path;
    $pdo->prepare(
        "UPDATE video_files
         SET duration_seconds = 277, width = 1920, height = 1080, fps = 29.97, video_codec = 'h264', audio_codec = 'aac', container_format = 'mov,mp4,m4a,3gp,3g2,mj2', bitrate = 1234567, metadata_status = 'ready', analyzed_at = UTC_TIMESTAMP()
         WHERE id = :id"
    )->execute(['id' => $videoId]);

    [$failedId, $failedPath] = video_stream_create_file_video($repository, $userId, $videoConfig, 'fallido.mp4', 'failed original', ['metadata_status' => 'failed']);
    $createdFiles[] = $failedPath;
    $pdo->prepare("UPDATE video_files SET metadata_status = 'failed', metadata_error = 'Invalid data', analyzed_at = UTC_TIMESTAMP() WHERE id = :id")->execute(['id' => $failedId]);

    [$otherVideoId, $otherPath] = video_stream_create_file_video($repository, $otherUserId, $videoConfig, 'ajeno.mp4', 'other-user-video', ['metadata_status' => 'ready']);
    $createdFiles[] = $otherPath;

    [$deleteVideoId, $deletePath] = video_stream_create_file_video($repository, $userId, $videoConfig, 'eliminar-detalle.mp4', 'delete-me-from-detail', ['metadata_status' => 'ready']);
    $createdFiles[] = $deletePath;

    [$pendingVideoId, $pendingPath] = video_stream_create_file_video($repository, $userId, $videoConfig, 'pendiente.mp4', 'pending-metadata-original', ['metadata_status' => 'pending']);
    $createdFiles[] = $pendingPath;

    [$unsafeVideoId, $unsafePath, $unsafeStoredName] = video_stream_create_file_video($repository, $userId, $videoConfig, 'unsafe.mp4', 'unsafe bytes', ['metadata_status' => 'ready']);
    $createdFiles[] = $unsafePath;
    $pdo->prepare('UPDATE video_files SET storage_path = :storage_path WHERE id = :id')->execute([
        'storage_path' => 'storage/video/uploads/../' . $unsafeStoredName,
        'id' => $unsafeVideoId,
    ]);

    $unauthenticated = video_stream_request('http://127.0.0.1/video/stream.php?id=' . $videoId);
    video_stream_assert($unauthenticated['status'] === 401, 'Unauthenticated user could stream video.');

    video_stream_login($username, $password, $cookieFile);
    video_stream_login($otherUsername, $otherPassword, $otherCookieFile);

    $page = video_stream_request('http://127.0.0.1/index.php?section=video', 'GET', null, $cookieFile);
    video_stream_assert($page['status'] === 200 && str_contains($page['body'], '04:37') && str_contains($page['body'], '1920&times;1080') && str_contains($page['body'], 'H.264 / AAC') && str_contains($page['body'], 'Listo'), 'Video list did not render ready metadata.');
    video_stream_assert(str_contains($page['body'], 'No se pudo analizar el video.') && str_contains($page['body'], 'Reintentar analisis'), 'Video list did not render failed metadata retry.');
    $csrf = video_stream_screen_csrf($page['body']);

    $detail = video_stream_request('http://127.0.0.1/index.php?section=video&id=' . $videoId, 'GET', null, $cookieFile);
    video_stream_assert($detail['status'] === 200 && str_contains($detail['body'], '<video') && str_contains($detail['body'], '/video/stream.php?id=' . $videoId) && str_contains($detail['body'], 'data-video-metadata-state="ready"'), 'Video detail/player did not render ready metadata.');
    video_stream_assert(str_contains($detail['body'], 'Duracion') && str_contains($detail['body'], 'Resolucion') && str_contains($detail['body'], 'FPS') && str_contains($detail['body'], 'Bitrate'), 'Ready metadata cards were not rendered.');

    $pendingDetail = video_stream_request('http://127.0.0.1/index.php?section=video&id=' . $pendingVideoId, 'GET', null, $cookieFile);
    video_stream_assert($pendingDetail['status'] === 200 && str_contains($pendingDetail['body'], 'Analizando informacion del video...') && !str_contains($pendingDetail['body'], '<dt>Duracion</dt>'), 'Pending detail rendered per-field placeholder metadata.');

    $failedDetail = video_stream_request('http://127.0.0.1/index.php?section=video&id=' . $failedId, 'GET', null, $cookieFile);
    video_stream_assert($failedDetail['status'] === 200 && str_contains($failedDetail['body'], 'No se pudo obtener la informacion tecnica del video.') && str_contains($failedDetail['body'], 'Reintentar analisis') && is_file($failedPath), 'Failed metadata state did not render or preserve original.');

    $retryForeign = video_stream_request('http://127.0.0.1/api/video/files.php?id=' . $otherVideoId . '&action=retry-metadata', 'POST', ['action' => 'retry-metadata'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_stream_assert($retryForeign['status'] === 404, 'Retry metadata allowed a foreign video.');

    $retryOwn = video_stream_request('http://127.0.0.1/api/video/files.php?id=' . $failedId . '&action=retry-metadata', 'POST', ['action' => 'retry-metadata'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_stream_assert($retryOwn['status'] === 200 && ($retryOwn['json']['data']['metadata_status'] ?? '') === 'pending' && is_file($failedPath), 'Retry metadata did not reset own failed video.');

    $detailForDelete = video_stream_request('http://127.0.0.1/index.php?section=video&id=' . $deleteVideoId, 'GET', null, $cookieFile);
    video_stream_assert($detailForDelete['status'] === 200 && str_contains($detailForDelete['body'], 'data-video-action="delete"'), 'Detail delete button was not rendered.');
    $deleteCsrf = video_stream_screen_csrf($detailForDelete['body']);

    $deleteViaGet = video_stream_request('http://127.0.0.1/api/video/files.php?id=' . $deleteVideoId . '&action=delete', 'GET', null, $cookieFile);
    video_stream_assert($deleteViaGet['status'] === 200 && is_file($deletePath) && $repository->findByIdForUser($userId, $deleteVideoId) !== null, 'Delete action was performed through GET.');

    $deleteNoCsrf = video_stream_request('http://127.0.0.1/api/video/files.php?id=' . $deleteVideoId . '&action=delete', 'POST', ['action' => 'delete'], $cookieFile);
    video_stream_assert($deleteNoCsrf['status'] === 403 && is_file($deletePath), 'Delete did not require CSRF.');

    $deleteForeign = video_stream_request('http://127.0.0.1/api/video/files.php?id=' . $otherVideoId . '&action=delete', 'POST', ['action' => 'delete'], $cookieFile, ['X-CSRF-Token: ' . $deleteCsrf]);
    video_stream_assert($deleteForeign['status'] === 404 && is_file($otherPath), 'Delete allowed a foreign video.');

    $deleteOwn = video_stream_request('http://127.0.0.1/api/video/files.php?id=' . $deleteVideoId . '&action=delete', 'POST', ['action' => 'delete'], $cookieFile, ['X-CSRF-Token: ' . $deleteCsrf]);
    clearstatcache(true, $deletePath);
    video_stream_assert($deleteOwn['status'] === 200 && !is_file($deletePath), 'Detail delete did not remove physical file.');
    video_stream_assert($repository->findByIdForUser($userId, $deleteVideoId) === null, 'Detail delete did not remove DB record.');
    video_stream_assert(($deleteOwn['json']['data']['message'] ?? '') === 'Video eliminado correctamente.', 'Detail delete did not return success message.');

    $normal = video_stream_request('http://127.0.0.1/video/stream.php?id=' . $videoId, 'GET', null, $cookieFile);
    video_stream_assert($normal['status'] === 200 && $normal['body'] === 'abcdefghijklmnopqrstuvwxyz' && stripos($normal['headers'], 'Accept-Ranges: bytes') !== false, 'Normal stream did not return 200 with full bytes.');

    $range = video_stream_request('http://127.0.0.1/video/stream.php?id=' . $videoId, 'GET', null, $cookieFile, ['Range: bytes=5-9']);
    video_stream_assert($range['status'] === 206 && $range['body'] === 'fghij', 'Valid byte range did not return expected bytes.');
    video_stream_assert(stripos($range['headers'], 'Content-Range: bytes 5-9/26') !== false, 'Valid byte range did not return correct Content-Range.');

    $invalidRange = video_stream_request('http://127.0.0.1/video/stream.php?id=' . $videoId, 'GET', null, $cookieFile, ['Range: bytes=99-100']);
    video_stream_assert($invalidRange['status'] === 416 && stripos($invalidRange['headers'], 'Content-Range: bytes */26') !== false, 'Invalid byte range did not return 416.');

    $multiRange = video_stream_request('http://127.0.0.1/video/stream.php?id=' . $videoId, 'GET', null, $cookieFile, ['Range: bytes=0-1,3-4']);
    video_stream_assert($multiRange['status'] === 416, 'Multi-range request was not rejected safely.');

    $foreignStream = video_stream_request('http://127.0.0.1/video/stream.php?id=' . $otherVideoId, 'GET', null, $cookieFile);
    video_stream_assert($foreignStream['status'] === 404, 'User streamed another user video.');

    $unsafeStream = video_stream_request('http://127.0.0.1/video/stream.php?id=' . $unsafeVideoId, 'GET', null, $cookieFile);
    video_stream_assert($unsafeStream['status'] === 404, 'Unsafe storage path was streamed.');

    $streamSource = file_get_contents(dirname(__DIR__, 2) . '/modules/Video/VideoStreamService.php');
    video_stream_assert(is_string($streamSource) && str_contains($streamSource, 'fread(') && !str_contains($streamSource, 'readfile('), 'Streaming implementation is not chunked.');

    $videoJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    video_stream_assert(is_string($videoJs) && str_contains($videoJs, "['pending', 'processing']") && str_contains($videoJs, 'stopMetadataPolling();') && str_contains($videoJs, "method: 'POST'"), 'Metadata polling or POST actions are not wired correctly.');

    echo "Video streaming: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video streaming: FAILED\n");
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
