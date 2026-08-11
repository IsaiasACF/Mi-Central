<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Video\VideoRepository;
use Modules\Video\VideoService;
use Modules\Video\VideoValidationException;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
$repository = new VideoRepository($pdo);
$service = new VideoService($repository, $videoConfig);
$username = 'test_video_upload_' . bin2hex(random_bytes(4));
$otherUsername = 'test_video_upload_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-video-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-video-other-');
$createdFiles = [];
$tempFiles = [];
$exitCode = 1;

function video_uploads_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function video_uploads_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function video_uploads_form_request(string $url, array $postFields, string $cookieFile): array
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

function video_uploads_upload(string $url, string $fieldName, string $filePath, string $clientName, string $mimeType, string $csrf, string $cookieFile): array
{
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'csrf_token' => $csrf,
            $fieldName => new CURLFile($filePath, $mimeType, $clientName),
        ],
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);

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

function video_uploads_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function video_uploads_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function video_uploads_login(string $username, string $password, string $cookieFile): void
{
    $loginPage = video_uploads_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = video_uploads_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => video_uploads_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    video_uploads_assert($login['status'] === 302, 'Test user could not log in.');
}

function video_uploads_file(string $contents, string $suffix): string
{
    $path = tempnam(sys_get_temp_dir(), 'mi-central-upload-');

    if (!is_string($path)) {
        throw new RuntimeException('Could not create temp file.');
    }

    $target = $path . $suffix;
    rename($path, $target);
    file_put_contents($target, $contents);

    return $target;
}

function video_uploads_mp4_file_with_size(int $sizeBytes): string
{
    $header = video_uploads_mp4_bytes();
    $path = video_uploads_file($header, '.mp4');
    $handle = fopen($path, 'ab');

    if (!is_resource($handle)) {
        throw new RuntimeException('Could not open temp video file.');
    }

    $remaining = max(0, $sizeBytes - strlen($header));
    $chunk = str_repeat("\0", 1024 * 1024);

    while ($remaining > 0) {
        $written = fwrite($handle, substr($chunk, 0, min(strlen($chunk), $remaining)));

        if (!is_int($written) || $written <= 0) {
            fclose($handle);
            throw new RuntimeException('Could not write temp video file.');
        }

        $remaining -= $written;
    }

    fclose($handle);

    return $path;
}

function video_uploads_mp4_bytes(): string
{
    return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat";
}

function video_uploads_absolute(array $config, array $video): string
{
    $uploads = is_string($config['uploads_path'] ?? null) ? (string) $config['uploads_path'] : dirname(__DIR__, 2) . '/storage/video/uploads';

    return rtrim($uploads, '/') . '/' . basename((string) $video['stored_name']);
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

    $unauthenticatedPage = video_uploads_request('http://127.0.0.1/index.php?section=video-editor');
    video_uploads_assert($unauthenticatedPage['status'] === 302, 'Video page should require authentication.');

    $unauthenticatedApi = video_uploads_request('http://127.0.0.1/api/video/files.php');
    video_uploads_assert($unauthenticatedApi['status'] === 401, 'Video API should require authentication.');

    video_uploads_login($username, $password, $cookieFile);
    video_uploads_login($otherUsername, $otherPassword, $otherCookieFile);

    $page = video_uploads_request('http://127.0.0.1/index.php?section=video-editor', 'GET', null, $cookieFile);
    video_uploads_assert($page['status'] === 200 && str_contains($page['body'], 'Seleccionar video') && str_contains($page['body'], 'Mis videos'), 'Video upload page did not render.');
    video_uploads_assert(str_contains($page['body'], 'Aun no has subido videos.'), 'Empty video state did not render.');
    $csrf = video_uploads_screen_csrf($page['body']);

    $mp4 = video_uploads_file(video_uploads_mp4_bytes(), '.mp4');
    $tempFiles[] = $mp4;

    $badCsrf = video_uploads_upload('http://127.0.0.1/api/video/files.php', 'video', $mp4, 'vacaciones.mp4', 'video/mp4', 'bad-token', $cookieFile);
    video_uploads_assert($badCsrf['status'] === 403, 'Video upload without valid CSRF was not rejected.');

    $missingFile = video_uploads_request('http://127.0.0.1/api/video/files.php', 'POST', ['csrf_token' => $csrf], $cookieFile);
    video_uploads_assert($missingFile['status'] === 422 && str_contains($missingFile['json']['error'] ?? '', 'Selecciona un video'), 'Upload without selected file did not return the specific no-file message.');

    $upload = video_uploads_upload('http://127.0.0.1/api/video/files.php', 'video', $mp4, 'vacaciones<script>.mp4', 'video/mp4', $csrf, $cookieFile);
    video_uploads_assert($upload['status'] === 201 && is_array($upload['json'] ?? null) && ($upload['json']['ok'] ?? false) === true, 'Allowed video upload failed.');
    $video = $upload['json']['data'];
    $storedVideo = $repository->findByIdForUser($userId, (int) ($video['id'] ?? 0));
    video_uploads_assert($storedVideo !== null, 'Uploaded video record was not persisted.');
    $createdFiles[] = video_uploads_absolute($videoConfig, $storedVideo);

    video_uploads_assert(!array_key_exists('user_id', $video) && !array_key_exists('stored_name', $video) && !array_key_exists('storage_path', $video), 'Video API exposed internal ownership or storage metadata.');
    video_uploads_assert((int) ($storedVideo['user_id'] ?? 0) === $userId, 'Uploaded video was not associated to the authenticated user.');
    video_uploads_assert(($video['original_name'] ?? '') === 'vacaciones<script>.mp4', 'Original name metadata was not preserved.');
    video_uploads_assert(preg_match('/\A[0-9a-f]{32}\.mp4\z/', (string) ($storedVideo['stored_name'] ?? '')) === 1, 'Stored name was not generated internally.');
    video_uploads_assert(!str_contains((string) ($storedVideo['storage_path'] ?? ''), 'vacaciones') && str_starts_with((string) ($storedVideo['storage_path'] ?? ''), 'storage/video/uploads/'), 'Original name was used as storage path.');
    video_uploads_assert(!str_starts_with((string) ($storedVideo['storage_path'] ?? ''), 'public/'), 'Uploaded video was stored under public.');
    video_uploads_assert(is_file($createdFiles[0]), 'Uploaded physical file was not stored.');
    video_uploads_assert(($video['metadata_status'] ?? '') === 'pending', 'Fresh upload did not start with pending metadata.');

    $immediateDetail = video_uploads_request('http://127.0.0.1/index.php?section=video-editor&id=' . (int) $video['id'], 'GET', null, $cookieFile);
    video_uploads_assert($immediateDetail['status'] === 200 && str_contains($immediateDetail['body'], 'vacaciones&lt;script&gt;.mp4') && str_contains($immediateDetail['body'], 'Analizando informacion del video...'), 'Freshly uploaded video detail was not immediately reachable.');

    $videoJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    video_uploads_assert(is_string($videoJs) && str_contains($videoJs, "window.location.href = '/index.php?section=video-editor'") && str_contains($videoJs, "titleLink.href = '/index.php?section=video-editor&id='"), 'Upload flow or dynamic links are not wired for immediate navigation.');

    $list = video_uploads_request('http://127.0.0.1/api/video/files.php', 'GET', null, $cookieFile);
    video_uploads_assert($list['status'] === 200 && count($list['json']['data'] ?? []) === 1, 'Video list did not return own upload.');

    $largeMp4 = video_uploads_mp4_file_with_size(45 * 1024 * 1024);
    $tempFiles[] = $largeMp4;
    $largeUpload = video_uploads_upload('http://127.0.0.1/api/video/files.php', 'video', $largeMp4, 'Reunion Ellison Parte 2.mp4', 'video/mp4', $csrf, $cookieFile);
    video_uploads_assert($largeUpload['status'] === 201 && is_array($largeUpload['json']['data'] ?? null), 'MP4 around 45 MB did not reach the backend upload flow.');
    $largeVideo = $largeUpload['json']['data'];
    $largeStoredVideo = $repository->findByIdForUser($userId, (int) ($largeVideo['id'] ?? 0));
    video_uploads_assert($largeStoredVideo !== null, 'MP4 around 45 MB was not persisted.');
    $largePath = video_uploads_absolute($videoConfig, $largeStoredVideo);
    $createdFiles[] = $largePath;
    video_uploads_assert(is_file($largePath), 'MP4 around 45 MB was not moved to storage/video/uploads.');

    $rendered = video_uploads_request('http://127.0.0.1/index.php?section=video-editor', 'GET', null, $cookieFile);
    video_uploads_assert(str_contains($rendered['body'], 'vacaciones&lt;script&gt;.mp4') && !str_contains($rendered['body'], 'vacaciones<script>.mp4'), 'Original video name was not escaped.');
    video_uploads_assert(!str_contains($rendered['body'], (string) ($storedVideo['storage_path'] ?? '')), 'Internal storage path was exposed in UI.');

    $avi = video_uploads_file(video_uploads_mp4_bytes(), '.avi');
    $tempFiles[] = $avi;
    $badExtension = video_uploads_upload('http://127.0.0.1/api/video/files.php', 'video', $avi, 'clip.avi', 'video/mp4', $csrf, $cookieFile);
    video_uploads_assert($badExtension['status'] === 422, 'Disallowed extension was not rejected.');

    $text = video_uploads_file('not a video', '.mp4');
    $tempFiles[] = $text;
    $badMime = video_uploads_upload('http://127.0.0.1/api/video/files.php', 'video', $text, 'clip.mp4', 'video/mp4', $csrf, $cookieFile);
    video_uploads_assert($badMime['status'] === 422 && str_contains($badMime['json']['error'] ?? '', 'video'), 'Invalid MIME was not rejected.');

    $double = video_uploads_file(video_uploads_mp4_bytes(), '.php');
    $tempFiles[] = $double;
    $doubleExtension = video_uploads_upload('http://127.0.0.1/api/video/files.php', 'video', $double, 'video.mp4.php', 'video/mp4', $csrf, $cookieFile);
    video_uploads_assert($doubleExtension['status'] === 422, 'Dangerous double extension was not rejected.');

    $oversized = video_uploads_file(str_repeat('0', 2 * 1024 * 1024), '.mp4');
    $tempFiles[] = $oversized;
    $smallLimitService = new VideoService($repository, array_merge($videoConfig, ['max_upload_mb' => 1]), static function (string $from, string $to): bool {
        return rename($from, $to);
    });

    try {
        $smallLimitService->upload($userId, [
            'name' => 'grande.mp4',
            'tmp_name' => $oversized,
            'size' => filesize($oversized),
            'error' => UPLOAD_ERR_OK,
        ]);
        throw new RuntimeException('Oversized upload was accepted.');
    } catch (VideoValidationException $exception) {
        video_uploads_assert(str_contains($exception->getMessage(), 'limite'), 'Oversized upload did not return a clear message.');
    }

    $otherMp4 = video_uploads_file(video_uploads_mp4_bytes(), '.mp4');
    $tempFiles[] = $otherMp4;
    $otherUploaded = (new VideoService($repository, $videoConfig, static function (string $from, string $to): bool {
        return copy($from, $to);
    }))->upload($otherUserId, [
        'name' => 'otro.mp4',
        'tmp_name' => $otherMp4,
        'size' => filesize($otherMp4),
        'error' => UPLOAD_ERR_OK,
    ]);
    $otherPath = video_uploads_absolute($videoConfig, $otherUploaded);
    $createdFiles[] = $otherPath;

    $ownListAfterOther = video_uploads_request('http://127.0.0.1/api/video/files.php', 'GET', null, $cookieFile);
    video_uploads_assert(count($ownListAfterOther['json']['data'] ?? []) === 2, 'Video list exposed another user video or lost an own video.');

    $foreignDelete = video_uploads_request('http://127.0.0.1/api/video/files.php?id=' . (int) $otherUploaded['id'], 'DELETE', ['storage_path' => 'storage/video/uploads/' . (string) $storedVideo['stored_name']], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_uploads_assert($foreignDelete['status'] === 404 && is_file($otherPath), 'Foreign video delete was not protected.');

    $pathOnlyDelete = video_uploads_request('http://127.0.0.1/api/video/files.php', 'DELETE', ['id' => (int) $video['id'], 'storage_path' => (string) $storedVideo['storage_path']], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_uploads_assert($pathOnlyDelete['status'] === 400 && is_file($createdFiles[0]), 'Delete accepted a browser-provided path or body id.');

    $deleteOwn = video_uploads_request('http://127.0.0.1/api/video/files.php?id=' . (int) $video['id'], 'DELETE', ['storage_path' => '../fake'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    clearstatcache(true, $createdFiles[0]);
    video_uploads_assert(
        $deleteOwn['status'] === 200 && !is_file($createdFiles[0]),
        'Own video was not deleted safely. Status: ' . $deleteOwn['status'] . '. Body: ' . $deleteOwn['body'] . '. File: ' . $createdFiles[0] . '. File exists: ' . (is_file($createdFiles[0]) ? 'yes' : 'no') . '.'
    );

    $missingUserFile = video_uploads_file(video_uploads_mp4_bytes(), '.mp4');
    $beforeOrphanCheck = glob(rtrim((string) ($videoConfig['uploads_path'] ?? dirname(__DIR__, 2) . '/storage/video/uploads'), '/') . '/*.mp4') ?: [];
    try {
        (new VideoService($repository, $videoConfig, static function (string $from, string $to): bool {
            return rename($from, $to);
        }))->upload(999999999, [
            'name' => 'orphan-check.mp4',
            'tmp_name' => $missingUserFile,
            'size' => filesize($missingUserFile),
            'error' => UPLOAD_ERR_OK,
        ]);
        throw new RuntimeException('Invalid user upload unexpectedly succeeded.');
    } catch (Throwable) {
        $afterOrphanCheck = glob(rtrim((string) ($videoConfig['uploads_path'] ?? dirname(__DIR__, 2) . '/storage/video/uploads'), '/') . '/*.mp4') ?: [];
        video_uploads_assert($beforeOrphanCheck === $afterOrphanCheck, 'Failed DB insert left an orphan file.');
    }

    echo "Video uploads: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video uploads: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach ($tempFiles as $file) {
        if (is_string($file) && is_file($file)) {
            unlink($file);
        }
    }

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
