<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Video\VideoCleanupService;
use Modules\Video\VideoCutPointRepository;
use Modules\Video\VideoEditSegmentRepository;
use Modules\Video\VideoEditorService;
use Modules\Video\VideoExportJobRepository;
use Modules\Video\VideoExportProcessor;
use Modules\Video\VideoExportService;
use Modules\Video\VideoRepository;
use Modules\Video\VideoStorage;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
$videoRepository = new VideoRepository($pdo);
$cutRepository = new VideoCutPointRepository($pdo);
$segmentRepository = new VideoEditSegmentRepository($pdo);
$jobRepository = new VideoExportJobRepository($pdo);
$editorService = new VideoEditorService($videoRepository, $cutRepository, $segmentRepository);
$exportService = new VideoExportService($videoRepository, $editorService, $jobRepository, new VideoStorage($videoConfig));
$processor = new VideoExportProcessor($videoConfig, $videoRepository, $jobRepository, new VideoStorage($videoConfig));
$username = 'test_video_exports_' . bin2hex(random_bytes(4));
$otherUsername = 'test_video_exports_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-exports-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-exports-other-');
$createdUploadFiles = [];
$createdExportFiles = [];
$exitCode = 1;

function video_exports_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function video_exports_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function video_exports_form_request(string $url, array $postFields, string $cookieFile): array
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

function video_exports_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1 || preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function video_exports_login(string $username, string $password, string $cookieFile): void
{
    $loginPage = video_exports_request('http://web/login.php', 'GET', null, $cookieFile);
    $login = video_exports_form_request('http://web/login.php', [
        'csrf_token' => video_exports_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    video_exports_assert($login['status'] === 302, 'Test user could not log in.');
}

function video_exports_uploads_path(array $videoConfig): string
{
    $path = $videoConfig['uploads_path'] ?? null;

    return is_string($path) && $path !== '' ? rtrim($path, '/') : dirname(__DIR__, 2) . '/storage/video/uploads';
}

function video_exports_exports_path(array $videoConfig): string
{
    $path = $videoConfig['exports_path'] ?? null;

    return is_string($path) && $path !== '' ? rtrim($path, '/') : dirname(__DIR__, 2) . '/storage/video/exports';
}

function video_exports_temp_path(array $videoConfig): string
{
    $path = $videoConfig['temp_path'] ?? null;

    return is_string($path) && $path !== '' ? rtrim($path, '/') : dirname(__DIR__, 2) . '/storage/video/temp';
}

function video_exports_run_process(array $command, string $message): string
{
    $pipes = [];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException($message);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException($message . ' ' . substr(trim((string) $stderr), 0, 400));
    }

    return is_string($stdout) ? $stdout : '';
}

function video_exports_create_media_file(array $videoConfig, string $path, float $durationSeconds): void
{
    $ffmpeg = is_string($videoConfig['ffmpeg_bin'] ?? null) ? $videoConfig['ffmpeg_bin'] : '/usr/bin/ffmpeg';
    video_exports_run_process([
        $ffmpeg,
        '-hide_banner',
        '-y',
        '-f',
        'lavfi',
        '-i',
        'testsrc=duration=' . number_format($durationSeconds, 3, '.', '') . ':size=64x64:rate=5',
        '-an',
        '-c:v',
        'libx264',
        '-preset',
        'ultrafast',
        '-pix_fmt',
        'yuv420p',
        $path,
    ], 'Could not create test video.');
}

function video_exports_create_video(VideoRepository $repository, int $userId, array $videoConfig, string $name, float $durationSeconds, bool $realMedia = true): array
{
    $storedName = bin2hex(random_bytes(16)) . '.mp4';
    $path = video_exports_uploads_path($videoConfig) . '/' . $storedName;

    if ($realMedia) {
        video_exports_create_media_file($videoConfig, $path, $durationSeconds);
    } else {
        file_put_contents($path, 'not a real mp4');
    }

    $id = $repository->create($userId, [
        'original_name' => $name,
        'stored_name' => $storedName,
        'storage_path' => 'storage/video/uploads/' . $storedName,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => filesize($path) ?: 1,
        'status' => 'pending_metadata',
        'metadata_status' => 'ready',
    ]);
    $statement = Connection::get()->prepare(
        "UPDATE video_files
         SET duration_seconds = :duration_seconds,
             width = 64,
             height = 64,
             fps = 5,
             video_codec = 'h264',
             audio_codec = NULL,
             container_format = 'mov,mp4',
             metadata_status = 'ready',
             analyzed_at = UTC_TIMESTAMP()
         WHERE id = :id"
    );
    $statement->execute([
        'duration_seconds' => number_format($durationSeconds, 3, '.', ''),
        'id' => $id,
    ]);

    return [$id, $path];
}

function video_exports_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute(['table' => $table]);

    return (int) $statement->fetchColumn() === 1;
}

function video_exports_output_duration(array $videoConfig, string $path): float
{
    $ffprobe = is_string($videoConfig['ffprobe_bin'] ?? null) ? $videoConfig['ffprobe_bin'] : '/usr/bin/ffprobe';
    $json = video_exports_run_process([
        $ffprobe,
        '-v',
        'error',
        '-show_format',
        '-show_streams',
        '-of',
        'json',
        $path,
    ], 'Could not inspect exported video.');
    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('FFprobe output was invalid.');
    }

    $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
    $hasVideo = false;

    foreach ($streams as $stream) {
        if (is_array($stream) && ($stream['codec_type'] ?? '') === 'video') {
            $hasVideo = true;
        }
    }

    video_exports_assert($hasVideo, 'Exported file does not contain video.');

    return (float) (($decoded['format']['duration'] ?? null) ?: 0);
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080009_create_video_files.php',
        '202608080010_add_video_file_metadata.php',
        '202608080011_create_video_cut_points.php',
        '202608080012_create_video_edit_segments.php',
        '202608080013_create_video_export_jobs.php',
        '202608080014_add_video_export_progress.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    video_exports_assert(video_exports_table_exists($pdo, 'video_export_jobs'), 'video_export_jobs table was not created.');
    video_exports_assert(video_exports_table_exists($pdo, 'video_export_segments'), 'video_export_segments table was not created.');
    video_exports_assert((int) ($videoConfig['export_retention_days'] ?? 0) === 30, 'Default export retention is not 30 days.');
    video_exports_assert($processor->progressPercent(-5.0, 10.0) === 0.0, 'Progress percent went below 0.');
    video_exports_assert($processor->progressPercent(99.0, 10.0) === 100.0, 'Progress percent went above 100.');
    $parsedProgress = $processor->parseProgressLine('out_time_us=5000000', 20.0);
    video_exports_assert(is_array($parsedProgress) && abs($parsedProgress['percent'] - 25.0) < 0.001 && abs((float) $parsedProgress['processed_seconds'] - 5.0) < 0.001, 'FFmpeg progress parsing failed.');

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    [$videoId, $path] = video_exports_create_video($videoRepository, $userId, $videoConfig, 'export-sequence.mp4', 180.0);
    $createdUploadFiles[] = $path;
    [$otherVideoId, $otherPath] = video_exports_create_video($videoRepository, $otherUserId, $videoConfig, 'other-export.mp4', 4.0);
    $createdUploadFiles[] = $otherPath;
    [$singleVideoId, $singlePath] = video_exports_create_video($videoRepository, $userId, $videoConfig, 'single-export.mp4', 3.0);
    $createdUploadFiles[] = $singlePath;
    [$badVideoId, $badPath] = video_exports_create_video($videoRepository, $userId, $videoConfig, 'bad-export.mp4', 2.0, false);
    $createdUploadFiles[] = $badPath;

    $originalHash = hash_file('sha256', $path);
    $originalSize = filesize($path);

    $editorService->create($userId, $videoId, 60.0);
    $editorService->create($userId, $videoId, 120.0);
    $segments = $editorService->listSegments($userId, $videoId)['segments'];
    $segmentOne = $segments[0];
    $segmentTwo = $segments[1];
    $segmentThree = $segments[2];
    $editorService->setSegmentIncluded($userId, (int) $segmentTwo['id'], false);
    $editorService->reorderSegments($userId, $videoId, [(int) $segmentThree['id'], (int) $segmentOne['id']]);

    video_exports_login($username, $password, $cookieFile);
    video_exports_login($otherUsername, $otherPassword, $otherCookieFile);
    $editorPage = video_exports_request('http://web/index.php?section=video&id=' . $videoId . '&editor=1', 'GET', null, $cookieFile);
    video_exports_assert($editorPage['status'] === 200 && str_contains($editorPage['body'], 'Exportar video') && str_contains($editorPage['body'], 'data-video-export-list'), 'Editor did not expose export UI.');
    $csrf = video_exports_csrf($editorPage['body']);

    $noCsrf = video_exports_request('http://web/api/video/exports.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create'], $cookieFile);
    video_exports_assert($noCsrf['status'] === 403, 'Creating an export did not require CSRF.');

    $foreign = video_exports_request('http://web/api/video/exports.php?video_id=' . $otherVideoId . '&action=create', 'POST', ['action' => 'create'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_exports_assert($foreign['status'] === 422, 'User created export for another user video.');

    $create = video_exports_request('http://web/api/video/exports.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'output_name' => 'Reunion editada; touch nope'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_exports_assert($create['status'] === 201 && (int) ($create['json']['data']['id'] ?? 0) > 0, 'Export job was not created.');
    $jobId = (int) $create['json']['data']['id'];
    $job = $jobRepository->findForUser($userId, $jobId);
    video_exports_assert(is_array($job) && preg_match('/\Aexport_[0-9a-f]{32}\.mp4\z/', (string) $job['output_stored_name']) === 1, 'Export physical filename was not generated safely.');
    video_exports_assert((float) ($job['progress_percent'] ?? -1) === 0.0 && $job['status'] === 'pending', 'New pending job did not start at 0 percent.');
    $pendingDownload = video_exports_request('http://web/video/export/download.php?id=' . $jobId, 'GET', null, $cookieFile);
    video_exports_assert($pendingDownload['status'] === 404, 'Pending job was downloadable.');
    $snapshot = $jobRepository->listSnapshotSegments($jobId);
    video_exports_assert(count($snapshot) === 2, 'Snapshot did not copy included segments.');
    video_exports_assert((float) $snapshot[0]['source_start_seconds'] === 120.0 && (float) $snapshot[0]['source_end_seconds'] === 180.0, 'Snapshot first segment order is wrong.');
    video_exports_assert((float) $snapshot[1]['source_start_seconds'] === 0.0 && (float) $snapshot[1]['source_end_seconds'] === 60.0, 'Snapshot second segment order is wrong.');

    $editorService->setSegmentIncluded($userId, (int) $segmentTwo['id'], true);
    $editorService->create($userId, $videoId, 30.0);
    $snapshotAfterEdit = $jobRepository->listSnapshotSegments($jobId);
    video_exports_assert($snapshotAfterEdit === $snapshot, 'Editing after job creation changed the snapshot.');

    $reserved = $jobRepository->reserveNextPending();
    video_exports_assert(is_array($reserved) && (int) $reserved['id'] === $jobId && $reserved['status'] === 'processing', 'Worker did not claim pending job atomically.');
    $jobRepository->updateProgress($jobId, 12.5, 15.0, '1.4x');
    $progressed = $jobRepository->findForUser($userId, $jobId);
    video_exports_assert(is_array($progressed) && abs((float) $progressed['progress_percent'] - 12.5) < 0.001 && abs((float) $progressed['processed_seconds'] - 15.0) < 0.001 && $progressed['speed'] === '1.4x', 'Progress update was not persisted.');
    video_exports_assert($jobRepository->reserveNextPending() === null, 'Worker claimed another job while one was processing.');
    $processor->process($reserved);
    $completedJob = $jobRepository->findForUser($userId, $jobId);
    video_exports_assert(is_array($completedJob) && $completedJob['status'] === 'completed', 'Job was not completed.');
    video_exports_assert(abs((float) ($completedJob['progress_percent'] ?? 0) - 100.0) < 0.001 && (float) ($completedJob['output_duration_seconds'] ?? 0) > 0.0 && $completedJob['expires_at'] !== null, 'Completed job did not persist 100 percent, output duration, and expiration.');
    video_exports_assert(str_starts_with((string) $completedJob['output_path'], 'storage/video/exports/'), 'Output path is not private export storage.');
    video_exports_assert(!str_starts_with((string) $completedJob['output_path'], 'public/'), 'Output was placed under public.');
    $completedPath = dirname(__DIR__, 2) . '/' . $completedJob['output_path'];
    $createdExportFiles[] = $completedPath;
    video_exports_assert(is_file($completedPath) && filesize($completedPath) > 0, 'Completed export file is missing.');
    $duration = video_exports_output_duration($videoConfig, $completedPath);
    video_exports_assert(abs($duration - 120.0) <= 2.0, 'Exported duration is not approximately 120 seconds.');
    $download = video_exports_request('http://web/video/export/download.php?id=' . $jobId, 'GET', null, $cookieFile);
    video_exports_assert($download['status'] === 200 && $download['body'] !== '' && str_contains($download['headers'], 'Content-Type: video/mp4') && str_contains($download['headers'], 'Content-Disposition: attachment; filename="Reunion_editada_touch_nope.mp4"'), 'Completed export was not downloadable with a safe name.');
    $stream = video_exports_request('http://web/video/export/stream.php?id=' . $jobId, 'GET', null, $cookieFile, ['Range: bytes=0-31']);
    video_exports_assert($stream['status'] === 206 && $stream['body'] !== '' && str_contains($stream['headers'], 'Content-Type: video/mp4') && str_contains($stream['headers'], 'Accept-Ranges: bytes'), 'Completed export was not streamable with byte ranges.');
    $foreignDownload = video_exports_request('http://web/video/export/download.php?id=' . $jobId, 'GET', null, $otherCookieFile);
    video_exports_assert($foreignDownload['status'] === 404, 'Another user downloaded an export.');
    $foreignStream = video_exports_request('http://web/video/export/stream.php?id=' . $jobId, 'GET', null, $otherCookieFile);
    video_exports_assert($foreignStream['status'] === 404, 'Another user streamed an export.');

    $singleCreate = $exportService->create($userId, $singleVideoId, 'Un segmento');
    $workerOutput = video_exports_run_process(['/usr/local/bin/php', 'workers/process-video-exports.php'], 'Export worker failed.');
    video_exports_assert(str_contains($workerOutput, 'Processed: 1') && str_contains($workerOutput, 'Completed: 1'), 'Export worker did not process single-segment job.');
    $singleJob = $jobRepository->findForUser($userId, (int) $singleCreate['id']);
    video_exports_assert(is_array($singleJob) && $singleJob['status'] === 'completed', 'Single-segment export did not complete.');
    $createdExportFiles[] = dirname(__DIR__, 2) . '/' . $singleJob['output_path'];

    $noneVideo = video_exports_create_video($videoRepository, $userId, $videoConfig, 'no-included.mp4', 2.0);
    $createdUploadFiles[] = $noneVideo[1];
    $noneSegments = $editorService->listSegments($userId, (int) $noneVideo[0])['segments'];
    $editorService->setSegmentIncluded($userId, (int) $noneSegments[0]['id'], false);
    try {
        $exportService->create($userId, (int) $noneVideo[0], 'Nada');
        video_exports_assert(false, 'Export without included segments was allowed.');
    } catch (Throwable $exception) {
        video_exports_assert(str_contains($exception->getMessage(), 'segmentos incluidos'), 'Export without included segments failed with wrong error.');
    }

    $badCreate = $exportService->create($userId, $badVideoId, 'Falla');
    $tempBefore = glob(video_exports_temp_path($videoConfig) . '/export_*') ?: [];
    $badWorkerOutput = video_exports_run_process(['/usr/local/bin/php', 'workers/process-video-exports.php'], 'Export worker failed on bad job.');
    video_exports_assert(str_contains($badWorkerOutput, 'Failed: 1'), 'Bad export did not fail in worker.');
    $badJob = $jobRepository->findForUser($userId, (int) $badCreate['id']);
    video_exports_assert(is_array($badJob) && $badJob['status'] === 'failed', 'Failed export was not marked failed.');
    video_exports_assert((float) ($badJob['progress_percent'] ?? 100) < 100.0, 'Failed export was falsely marked 100 percent.');
    $failedDownload = video_exports_request('http://web/video/export/download.php?id=' . (int) $badCreate['id'], 'GET', null, $cookieFile);
    video_exports_assert($failedDownload['status'] === 404, 'Failed job was downloadable.');
    $failedStream = video_exports_request('http://web/video/export/stream.php?id=' . (int) $badCreate['id'], 'GET', null, $cookieFile);
    video_exports_assert($failedStream['status'] === 404, 'Failed job was streamable.');
    $tempAfter = glob(video_exports_temp_path($videoConfig) . '/export_*') ?: [];
    video_exports_assert($tempAfter === $tempBefore, 'Failed export left temporary files behind.');

    clearstatcache(true, $path);
    video_exports_assert(is_file($path) && hash_file('sha256', $path) === $originalHash && filesize($path) === $originalSize, 'Original file changed after export operations.');

    $outsideJobId = $jobRepository->createWithSegments($userId, $videoId, 'Outside', 'export_' . bin2hex(random_bytes(16)) . '.mp4', 1.0, [['source_start_seconds' => 0.0, 'source_end_seconds' => 1.0]]);
    $outsideJob = $jobRepository->findForUser($userId, $outsideJobId);
    video_exports_assert(is_array($outsideJob), 'Outside-path test job was not created.');
    $pdo->prepare("UPDATE video_export_jobs SET status = 'completed', output_path = :path, progress_percent = 100, completed_at = UTC_TIMESTAMP() WHERE id = :id")->execute([
        'path' => 'storage/video/uploads/' . basename($path),
        'id' => $outsideJobId,
    ]);
    $outsideDownload = video_exports_request('http://web/video/export/download.php?id=' . $outsideJobId, 'GET', null, $cookieFile);
    video_exports_assert($outsideDownload['status'] === 404, 'Download accepted an output outside exports.');

    $deleteJobId = $jobRepository->createWithSegments($userId, $videoId, 'Delete me', 'export_' . bin2hex(random_bytes(16)) . '.mp4', 1.0, [['source_start_seconds' => 0.0, 'source_end_seconds' => 1.0]]);
    $deleteJob = $jobRepository->findForUser($userId, $deleteJobId);
    video_exports_assert(is_array($deleteJob), 'Delete test job was not created.');
    $deletePath = video_exports_exports_path($videoConfig) . '/' . $deleteJob['output_stored_name'];
    file_put_contents($deletePath, 'exported-bytes');
    $createdExportFiles[] = $deletePath;
    $pdo->prepare("UPDATE video_export_jobs SET status = 'completed', output_path = :path, output_size_bytes = 14, progress_percent = 100, completed_at = UTC_TIMESTAMP(), expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY) WHERE id = :id")->execute([
        'path' => 'storage/video/exports/' . $deleteJob['output_stored_name'],
        'id' => $deleteJobId,
    ]);
    $otherEditorPage = video_exports_request('http://web/index.php?section=video&id=' . $otherVideoId . '&editor=1', 'GET', null, $otherCookieFile);
    $deleteForeign = video_exports_request('http://web/api/video/exports.php?id=' . $deleteJobId . '&action=delete', 'POST', ['action' => 'delete'], $otherCookieFile, ['X-CSRF-Token: ' . video_exports_csrf($otherEditorPage['body'])]);
    video_exports_assert($deleteForeign['status'] === 404, 'Another user deleted an export.');
    $deleteOwn = video_exports_request('http://web/api/video/exports.php?id=' . $deleteJobId . '&action=delete', 'POST', ['action' => 'delete'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_exports_assert($deleteOwn['status'] === 200 && !is_file($deletePath) && $jobRepository->findForUser($userId, $deleteJobId) === null, 'Own export was not deleted cleanly.');
    clearstatcache(true, $path);
    video_exports_assert(is_file($path) && hash_file('sha256', $path) === $originalHash, 'Deleting export touched original video.');

    $oldTemp = video_exports_temp_path($videoConfig) . '/export_999999_' . bin2hex(random_bytes(8)) . '.tmp.mp4';
    file_put_contents($oldTemp, 'old-temp');
    touch($oldTemp, time() - 90000);
    $processingJobId = $jobRepository->createWithSegments($userId, $videoId, 'Processing temp', 'export_' . bin2hex(random_bytes(16)) . '.mp4', 1.0, [['source_start_seconds' => 0.0, 'source_end_seconds' => 1.0]]);
    $processingReserved = $jobRepository->reserveNextPending();
    video_exports_assert(is_array($processingReserved) && (int) $processingReserved['id'] === $processingJobId, 'Could not reserve processing cleanup job.');
    $processingTemp = video_exports_temp_path($videoConfig) . '/export_' . $processingJobId . '_' . bin2hex(random_bytes(8)) . '.tmp.mp4';
    file_put_contents($processingTemp, 'processing-temp');
    touch($processingTemp, time() - 90000);

    $expiredJobId = $jobRepository->createWithSegments($userId, $videoId, 'Expired', 'export_' . bin2hex(random_bytes(16)) . '.mp4', 1.0, [['source_start_seconds' => 0.0, 'source_end_seconds' => 1.0]]);
    $expiredJob = $jobRepository->findForUser($userId, $expiredJobId);
    video_exports_assert(is_array($expiredJob), 'Expired test job was not created.');
    $expiredPath = video_exports_exports_path($videoConfig) . '/' . $expiredJob['output_stored_name'];
    file_put_contents($expiredPath, 'expired-export');
    $createdExportFiles[] = $expiredPath;
    $pdo->prepare("UPDATE video_export_jobs SET status = 'completed', output_path = :path, output_size_bytes = 14, progress_percent = 100, completed_at = UTC_TIMESTAMP(), expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = :id")->execute([
        'path' => 'storage/video/exports/' . $expiredJob['output_stored_name'],
        'id' => $expiredJobId,
    ]);
    $freshJobId = $jobRepository->createWithSegments($userId, $videoId, 'Fresh', 'export_' . bin2hex(random_bytes(16)) . '.mp4', 1.0, [['source_start_seconds' => 0.0, 'source_end_seconds' => 1.0]]);
    $freshJob = $jobRepository->findForUser($userId, $freshJobId);
    video_exports_assert(is_array($freshJob), 'Fresh test job was not created.');
    $freshPath = video_exports_exports_path($videoConfig) . '/' . $freshJob['output_stored_name'];
    file_put_contents($freshPath, 'fresh-export');
    $createdExportFiles[] = $freshPath;
    $pdo->prepare("UPDATE video_export_jobs SET status = 'completed', output_path = :path, output_size_bytes = 12, progress_percent = 100, completed_at = UTC_TIMESTAMP(), expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY) WHERE id = :id")->execute([
        'path' => 'storage/video/exports/' . $freshJob['output_stored_name'],
        'id' => $freshJobId,
    ]);
    $cleanup = new VideoCleanupService($videoConfig, $jobRepository, new VideoStorage($videoConfig));
    $cleanupSummary = $cleanup->cleanup();
    video_exports_assert($cleanupSummary['temporary_files_removed'] >= 1 && !is_file($oldTemp), 'Cleanup did not remove old temporary file.');
    video_exports_assert(is_file($processingTemp), 'Cleanup removed temp file for processing job.');
    video_exports_assert($cleanupSummary['expired_exports_removed'] >= 1 && !is_file($expiredPath) && $jobRepository->findForUser($userId, $expiredJobId) === null, 'Cleanup did not remove expired export.');
    video_exports_assert(is_file($freshPath) && $jobRepository->findForUser($userId, $freshJobId) !== null, 'Cleanup removed non-expired export.');
    clearstatcache(true, $path);
    video_exports_assert(is_file($path) && hash_file('sha256', $path) === $originalHash, 'Cleanup touched original video.');
    $jobRepository->markFailed($processingJobId, 'cleanup test done');

    if (is_file($processingTemp)) {
        unlink($processingTemp);
    }

    $processorSource = file_get_contents(dirname(__DIR__, 2) . '/modules/Video/VideoExportProcessor.php');
    video_exports_assert(is_string($processorSource) && str_contains($processorSource, "'-progress'") && str_contains($processorSource, "'pipe:1'") && str_contains($processorSource, 'proc_open($command') && !str_contains($processorSource, 'shell_exec') && !str_contains($processorSource, 'passthru') && !str_contains($processorSource, 'system('), 'FFmpeg execution is not using controlled progress and arguments.');
    $downloadSource = file_get_contents(dirname(__DIR__, 2) . '/public/video/export/download.php');
    video_exports_assert(is_string($downloadSource) && str_contains($downloadSource, 'fread($handle, 8192)') && !str_contains($downloadSource, 'readfile'), 'Download endpoint does not stream by chunks.');

    $crontab = file_get_contents(dirname(__DIR__, 2) . '/docker/worker/crontab');
    video_exports_assert(is_string($crontab) && str_contains($crontab, 'process-reminders.php') && str_contains($crontab, 'process-notification-activity.php') && str_contains($crontab, 'process-video-metadata.php') && str_contains($crontab, 'process-video-exports.php') && str_contains($crontab, 'cleanup-video-files.php'), 'Worker crontab lost reminders, activity, metadata, exports, or cleanup.');

    $list = video_exports_request('http://web/api/video/exports.php?video_id=' . $videoId, 'GET', null, $cookieFile);
    video_exports_assert($list['status'] === 200 && count($list['json']['data'] ?? []) >= 1, 'Export list did not return own jobs.');
    $pendingPanelJobId = $jobRepository->createWithSegments($userId, $videoId, 'Pending panel', 'export_' . bin2hex(random_bytes(16)) . '.mp4', 1.0, [['source_start_seconds' => 0.0, 'source_end_seconds' => 1.0]]);
    $processedApi = video_exports_request('http://web/api/video/exports.php', 'GET', null, $cookieFile);
    $processedItems = $processedApi['json']['data'] ?? [];
    video_exports_assert($processedApi['status'] === 200 && is_array($processedItems) && count($processedItems) >= 3, 'Processed exports API did not list real exports.');
    video_exports_assert((int) ($processedItems[0]['id'] ?? 0) === $pendingPanelJobId && ($processedItems[0]['display_status'] ?? '') === 'pending', 'Processed exports were not ordered newest first with pending state.');
    video_exports_assert(($processedItems[0]['video_original_name'] ?? '') === 'export-sequence.mp4', 'Processed export did not include related original video.');
    video_exports_assert(array_reduce($processedItems, static fn (bool $found, mixed $item): bool => $found || (is_array($item) && (int) ($item['id'] ?? 0) === $jobId && ($item['is_downloadable'] ?? false) === true), false), 'Completed processed export was not marked downloadable.');
    $legacyProcessedPage = video_exports_request('http://web/index.php?section=video-editor&tab=processed', 'GET', null, $cookieFile);
    video_exports_assert($legacyProcessedPage['status'] === 302 && str_contains($legacyProcessedPage['headers'], 'Location: /index.php?section=video&tab=processings'), 'Legacy processed tab did not redirect to internal Video tab.');
    $editorListPage = video_exports_request('http://web/index.php?section=video', 'GET', null, $cookieFile);
    video_exports_assert($editorListPage['status'] === 200 && str_contains($editorListPage['body'], 'Editor') && str_contains($editorListPage['body'], 'Procesamientos') && !str_contains($editorListPage['body'], 'data-video-processed-panel') && preg_match('/class="nav-link is-active"[^>]*href="\/index\.php\?section=video"/', $editorListPage['body']) === 1, 'Editor did not render internal tabs or active Video sidebar state.');
    video_exports_assert(!str_contains($editorListPage['body'], 'section=video-processing') && !str_contains($editorListPage['body'], 'section=video-editor'), 'Video sidebar still exposes editor/processing submenu links.');
    $processedPage = video_exports_request('http://web/index.php?section=video&tab=processings', 'GET', null, $cookieFile);
    video_exports_assert($processedPage['status'] === 200 && str_contains($processedPage['body'], 'Procesamientos') && str_contains($processedPage['body'], 'Pending panel') && str_contains($processedPage['body'], 'Reproducir') && str_contains($processedPage['body'], 'Descargar') && !str_contains($processedPage['body'], 'Transcribir') && str_contains($processedPage['body'], '/video/export/stream.php?id=' . $jobId), 'Processing sidebar page did not render real export history and actions.');
    video_exports_assert(!str_contains($processedPage['body'], 'Procesados') && !str_contains($processedPage['body'], 'Duracion: Sin datos') && !str_contains($processedPage['body'], 'Acceso visual al futuro editor de video') && !str_contains($processedPage['body'], 'No hay procesamientos recientes.') && preg_match('/class="nav-link is-active"[^>]*href="\/index\.php\?section=video"/', $processedPage['body']) === 1 && str_contains($processedPage['body'], 'href="/index.php?section=video&amp;tab=processings" aria-current="page"'), 'Processing page kept old labels, placeholders, or wrong active sidebar/internal tab state.');
    $emptyProcessedPage = video_exports_request('http://web/index.php?section=video&tab=processings', 'GET', null, $otherCookieFile);
    video_exports_assert($emptyProcessedPage['status'] === 200 && str_contains($emptyProcessedPage['body'], 'Aun no has procesado videos.') && str_contains($emptyProcessedPage['body'], 'Ir al editor'), 'Processed empty state is not useful.');
    video_exports_assert(!str_contains($processedPage['body'], 'section=video-settings'), 'Video sidebar still exposes duplicated settings.');
    $settingsPage = video_exports_request('http://web/index.php?section=settings', 'GET', null, $cookieFile);
    video_exports_assert($settingsPage['status'] === 200 && str_contains($settingsPage['body'], 'Configuracion') && preg_match('/class="nav-link is-active"[^>]*href="\/index\.php\?section=settings"/', $settingsPage['body']) === 1 && !str_contains($settingsPage['body'], 'section=video-settings'), 'General settings route did not render or mark sidebar active.');
    $dashboard = video_exports_request('http://web/index.php', 'GET', null, $cookieFile);
    video_exports_assert($dashboard['status'] === 200 && str_contains($dashboard['body'], 'Edita y exporta tus videos.') && str_contains($dashboard['body'], 'Exportacion en cola: Pending panel') && str_contains($dashboard['body'], '/index.php?section=video&amp;tab=processings') && !str_contains($dashboard['body'], 'Acceso visual al futuro editor de video') && !str_contains($dashboard['body'], 'No hay procesamientos recientes.'), 'Dashboard video card did not show real video activity.');

    echo "Video exports: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video exports: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    foreach (array_merge($createdExportFiles, $createdUploadFiles) as $file) {
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
