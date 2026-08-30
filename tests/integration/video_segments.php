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
$username = 'test_video_segments_' . bin2hex(random_bytes(4));
$otherUsername = 'test_video_segments_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-segments-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-segments-other-');
$createdFiles = [];
$exitCode = 1;

function video_segments_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function video_segments_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function video_segments_form_request(string $url, array $postFields, string $cookieFile): array
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

function video_segments_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function video_segments_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function video_segments_login(string $username, string $password, string $cookieFile): void
{
    $loginPage = video_segments_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = video_segments_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => video_segments_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    video_segments_assert($login['status'] === 302, 'Test user could not log in.');
}

function video_segments_uploads_path(array $videoConfig): string
{
    $path = $videoConfig['uploads_path'] ?? null;

    return is_string($path) && $path !== '' ? rtrim($path, '/') : dirname(__DIR__, 2) . '/storage/video/uploads';
}

function video_segments_create_video(VideoRepository $repository, int $userId, array $videoConfig, string $name, string $contents, float $duration = 300.0): array
{
    $storedName = bin2hex(random_bytes(16)) . '.mp4';
    $path = video_segments_uploads_path($videoConfig) . '/' . $storedName;
    file_put_contents($path, $contents);
    $id = $repository->create($userId, [
        'original_name' => $name,
        'stored_name' => $storedName,
        'storage_path' => 'storage/video/uploads/' . $storedName,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => strlen($contents),
        'status' => 'pending_metadata',
        'metadata_status' => 'ready',
    ]);

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

    return [$id, $path];
}

function video_segments_table_exists(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute(['table' => 'video_edit_segments']);

    return (int) $statement->fetchColumn() === 1;
}

function video_segments_ranges(array $collection): array
{
    $segments = $collection['segments'] ?? [];
    usort($segments, static fn (array $a, array $b): int => (float) $a['source_start_seconds'] <=> (float) $b['source_start_seconds']);

    return array_map(
        static fn (array $segment): array => [
            (float) $segment['source_start_seconds'],
            (float) $segment['source_end_seconds'],
        ],
        $segments
    );
}

function video_segments_assert_contiguous(array $collection, float $duration): void
{
    $segments = $collection['segments'] ?? [];
    usort($segments, static fn (array $a, array $b): int => (float) $a['source_start_seconds'] <=> (float) $b['source_start_seconds']);
    $cursor = 0.0;

    foreach ($segments as $segment) {
        $start = (float) $segment['source_start_seconds'];
        $end = (float) $segment['source_end_seconds'];
        video_segments_assert(abs($start - $cursor) < 0.001, 'Segments have a gap or overlap.');
        video_segments_assert($start < $end, 'Segment range is invalid.');
        $cursor = $end;
    }

    video_segments_assert(abs($cursor - $duration) < 0.001, 'Segments do not cover the original duration.');
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

    video_segments_assert(video_segments_table_exists($pdo), 'video_edit_segments table was not created.');

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    [$videoId, $path] = video_segments_create_video($videoRepository, $userId, $videoConfig, 'segments-own.mp4', 'abcdefghijklmnopqrstuvwxyz0123456789', 300.0);
    $createdFiles[] = $path;
    [$otherVideoId, $otherPath] = video_segments_create_video($videoRepository, $otherUserId, $videoConfig, 'segments-other.mp4', 'other-original-bytes', 300.0);
    $createdFiles[] = $otherPath;

    $originalHash = hash_file('sha256', $path);
    $originalSize = filesize($path);

    video_segments_login($username, $password, $cookieFile);
    video_segments_login($otherUsername, $otherPassword, $otherCookieFile);

    $detail = video_segments_request('http://127.0.0.1/index.php?section=video&id=' . $videoId . '&editor=1', 'GET', null, $cookieFile);
    video_segments_assert($detail['status'] === 200 && str_contains($detail['body'], 'data-video-segments-panel') && str_contains($detail['body'], 'data-video-timeline'), 'Editor did not render timeline and segments.');
    $csrf = video_segments_screen_csrf($detail['body']);

    $noCuts = $editorService->listSegments($userId, $videoId);
    video_segments_assert(video_segments_ranges($noCuts) === [[0.0, 300.0]], 'Video without cuts did not generate one segment.');

    $create60 = video_segments_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 60], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($create60['status'] === 201, 'Adding a cut failed.');
    $cut60Id = (int) ($create60['json']['data']['cut_point']['id'] ?? 0);
    $afterSplit = $editorService->listSegments($userId, $videoId);
    video_segments_assert(video_segments_ranges($afterSplit) === [[0.0, 60.0], [60.0, 300.0]], 'Adding a cut did not split a segment.');

    $create150 = video_segments_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 150], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    $create240 = video_segments_request('http://127.0.0.1/api/video/cuts.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'position_seconds' => 240], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($create150['status'] === 201 && $create240['status'] === 201, 'Additional cuts failed.');
    $cut150Id = (int) ($create150['json']['data']['cut_point']['id'] ?? 0);
    $cut240Id = (int) ($create240['json']['data']['cut_point']['id'] ?? 0);

    $fourSegments = $editorService->listSegments($userId, $videoId);
    video_segments_assert(video_segments_ranges($fourSegments) === [[0.0, 60.0], [60.0, 150.0], [150.0, 240.0], [240.0, 300.0]], 'Three cuts did not generate four segments.');
    video_segments_assert_contiguous($fourSegments, 300.0);

    $segmentIds = array_map(static fn (array $segment): int => (int) $segment['id'], $fourSegments['segments']);
    $sourceTimesById = [];
    foreach ($fourSegments['segments'] as $segment) {
        $sourceTimesById[(int) $segment['id']] = [(float) $segment['source_start_seconds'], (float) $segment['source_end_seconds']];
    }

    $noCsrf = video_segments_request('http://127.0.0.1/api/video/segments.php?id=' . $segmentIds[1] . '&action=exclude', 'POST', ['action' => 'exclude'], $cookieFile);
    video_segments_assert($noCsrf['status'] === 403, 'Segment write did not require CSRF.');

    $exclude = video_segments_request('http://127.0.0.1/api/video/segments.php?id=' . $segmentIds[1] . '&action=exclude', 'POST', ['action' => 'exclude'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($exclude['status'] === 200 && (int) ($exclude['json']['data']['summary']['included_segments'] ?? 0) === 3, 'Excluding segment failed.');
    $exportWithoutExcluded = $editorService->getExportSequence($videoId, $userId);
    video_segments_assert(count($exportWithoutExcluded) === 3 && $exportWithoutExcluded[1] === ['source_start_seconds' => 150.0, 'source_end_seconds' => 240.0], 'Excluded segment appeared in export sequence.');

    $restore = video_segments_request('http://127.0.0.1/api/video/segments.php?id=' . $segmentIds[1] . '&action=restore', 'POST', ['action' => 'restore'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($restore['status'] === 200 && (int) ($restore['json']['data']['summary']['included_segments'] ?? 0) === 4, 'Restoring segment failed.');

    $reorderIds = [$segmentIds[2], $segmentIds[0], $segmentIds[3], $segmentIds[1]];
    $reorder = video_segments_request('http://127.0.0.1/api/video/segments.php?video_id=' . $videoId . '&action=reorder', 'POST', ['action' => 'reorder', 'segment_ids' => $reorderIds], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($reorder['status'] === 200 && ($reorder['json']['data']['summary']['sequence'] ?? []) === [3, 1, 4, 2], 'Reordering segments failed.');
    $afterReorder = $editorService->listSegments($userId, $videoId);
    foreach ($sourceTimesById as $segmentId => $times) {
        $current = array_values(array_filter($afterReorder['segments'], static fn (array $segment): bool => (int) $segment['id'] === $segmentId))[0] ?? null;
        video_segments_assert(is_array($current) && [(float) $current['source_start_seconds'], (float) $current['source_end_seconds']] === $times, 'Reorder changed source times.');
    }
    video_segments_assert(abs((float) ($afterReorder['summary']['final_duration_seconds'] ?? 0) - 300.0) < 0.001, 'Final estimated duration is wrong.');

    $duplicateOrder = video_segments_request('http://127.0.0.1/api/video/segments.php?video_id=' . $videoId . '&action=reorder', 'POST', ['action' => 'reorder', 'segment_ids' => [$segmentIds[0], $segmentIds[0]]], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($duplicateOrder['status'] === 422, 'Duplicated segment IDs were not rejected.');

    $otherCollection = $editorService->listSegments($otherUserId, $otherVideoId);
    $foreignId = (int) ($otherCollection['segments'][0]['id'] ?? 0);
    $foreignOrder = video_segments_request('http://127.0.0.1/api/video/segments.php?video_id=' . $videoId . '&action=reorder', 'POST', ['action' => 'reorder', 'segment_ids' => [$segmentIds[0], $segmentIds[1], $foreignId, $segmentIds[3]]], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($foreignOrder['status'] === 422, 'Segment ID from another video was accepted.');

    $foreignExclude = video_segments_request('http://127.0.0.1/api/video/segments.php?id=' . $foreignId . '&action=exclude', 'POST', ['action' => 'exclude'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($foreignExclude['status'] === 404, 'User modified another user segment.');

    $deleteCut = video_segments_request('http://127.0.0.1/api/video/cuts.php?id=' . $cut150Id . '&video_id=' . $videoId . '&action=delete', 'POST', ['action' => 'delete'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($deleteCut['status'] === 200, 'Deleting cut failed.');
    $afterMerge = $editorService->listSegments($userId, $videoId);
    video_segments_assert(video_segments_ranges($afterMerge) === [[0.0, 60.0], [60.0, 240.0], [240.0, 300.0]], 'Deleting a cut did not fuse adjacent segments.');
    video_segments_assert_contiguous($afterMerge, 300.0);

    $moveCut = video_segments_request('http://127.0.0.1/api/video/cuts.php?id=' . $cut240Id . '&action=update', 'POST', ['action' => 'update', 'position_seconds' => 200], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_segments_assert($moveCut['status'] === 200, 'Moving cut failed.');
    $afterMove = $editorService->listSegments($userId, $videoId);
    video_segments_assert(video_segments_ranges($afterMove) === [[0.0, 60.0], [60.0, 200.0], [200.0, 300.0]], 'Moving a cut did not update adjacent segment limits.');
    video_segments_assert_contiguous($afterMove, 300.0);

    $serviceSegments = $editorService->virtualSegments(300.0, [60.0, 150.0, 240.0]);
    video_segments_assert($serviceSegments === [
        ['start' => 0.0, 'end' => 60.0],
        ['start' => 60.0, 'end' => 150.0],
        ['start' => 150.0, 'end' => 240.0],
        ['start' => 240.0, 'end' => 300.0],
    ], 'Timeline cut point derivation regressed.');

    clearstatcache(true, $path);
    video_segments_assert(is_file($path) && hash_file('sha256', $path) === $originalHash && filesize($path) === $originalSize, 'Original file changed after segment operations.');

    foreach (['public/api/video/segments.php', 'modules/Video/VideoEditorService.php', 'modules/Video/VideoEditSegmentRepository.php'] as $sourcePath) {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $sourcePath);
        video_segments_assert(is_string($source) && !str_contains(strtolower($source), 'ffmpeg') && !str_contains(strtolower($source), 'ffprobe') && !str_contains($source, 'proc_open'), 'Segment operations reference media processing.');
    }

    echo "Video segments: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video segments: FAILED\n");
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
