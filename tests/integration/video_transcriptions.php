<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Video\TranscriptionSourceResolver;
use Modules\Video\VideoCleanupService;
use Modules\Video\VideoExportJobRepository;
use Modules\Video\VideoRepository;
use Modules\Video\VideoStorage;
use Modules\Video\VideoTranscriptionRepository;
use Modules\Video\VideoTranscriptionService;
use Modules\Video\VideoValidationException;
use Modules\Video\WhisperService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$videoConfig = is_array($config['video'] ?? null) ? $config['video'] : [];
$storage = new VideoStorage($videoConfig);
$videoRepository = new VideoRepository($pdo);
$exportRepository = new VideoExportJobRepository($pdo);
$transcriptionRepository = new VideoTranscriptionRepository($pdo);
$whisper = new WhisperService($videoConfig, $storage);
$sourceResolver = new TranscriptionSourceResolver($videoRepository, $exportRepository, $storage);
$transcriptionService = new VideoTranscriptionService($transcriptionRepository, $sourceResolver, $whisper);
$username = 'test_video_transcriptions_' . bin2hex(random_bytes(4));
$otherUsername = 'test_video_transcriptions_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-transcriptions-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-transcriptions-other-');
$createdUploadFiles = [];
$createdExportFiles = [];
$createdTempFiles = [];
$exitCode = 1;

function video_transcriptions_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function video_transcriptions_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

function video_transcriptions_form_request(string $url, array $postFields, string $cookieFile): array
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

function video_transcriptions_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1 || preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function video_transcriptions_login(string $username, string $password, string $cookieFile): void
{
    $loginPage = video_transcriptions_request('http://web/login.php', 'GET', null, $cookieFile);
    $login = video_transcriptions_form_request('http://web/login.php', [
        'csrf_token' => video_transcriptions_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    video_transcriptions_assert($login['status'] === 302, 'Test user could not log in.');
}

function video_transcriptions_run_process(array $command, string $message): string
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
        throw new RuntimeException($message . ' ' . substr(trim((string) $stderr), 0, 500));
    }

    return is_string($stdout) ? $stdout : '';
}

function video_transcriptions_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute(['table' => $table]);

    return (int) $statement->fetchColumn() === 1;
}

function video_transcriptions_create_voice_video(VideoRepository $repository, VideoStorage $storage, array $videoConfig, int $userId, string $name, string $speech): array
{
    $storage->ensureUploadsDirectory();
    $storedName = bin2hex(random_bytes(16)) . '.mp4';
    $path = $storage->destinationForStoredName($storedName);
    $ffmpeg = is_string($videoConfig['ffmpeg_bin'] ?? null) ? $videoConfig['ffmpeg_bin'] : '/usr/bin/ffmpeg';
    video_transcriptions_run_process([
        $ffmpeg,
        '-hide_banner',
        '-y',
        '-f',
        'lavfi',
        '-i',
        'testsrc=duration=3:size=64x64:rate=5',
        '-f',
        'lavfi',
        '-i',
        "flite=text='{$speech}':voice=kal",
        '-t',
        '3',
        '-shortest',
        '-c:v',
        'libx264',
        '-preset',
        'ultrafast',
        '-pix_fmt',
        'yuv420p',
        '-c:a',
        'aac',
        $path,
    ], 'Could not create voice test video.');

    $id = $repository->create($userId, [
        'original_name' => $name,
        'stored_name' => $storedName,
        'storage_path' => $storage->relativePathForStoredName($storedName),
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => filesize($path) ?: 1,
        'status' => 'pending_metadata',
        'metadata_status' => 'ready',
    ]);
    Connection::get()->prepare(
        "UPDATE video_files
         SET duration_seconds = 3,
             width = 64,
             height = 64,
             fps = 5,
             video_codec = 'h264',
             audio_codec = 'aac',
             container_format = 'mov,mp4',
             bitrate = :bitrate,
             metadata_status = 'ready',
             analyzed_at = UTC_TIMESTAMP()
         WHERE id = :id"
    )->execute([
        'bitrate' => max(1, filesize($path) ?: 1),
        'id' => $id,
    ]);

    return [$id, $path];
}

function video_transcriptions_create_bad_video(VideoRepository $repository, VideoStorage $storage, int $userId): array
{
    $storage->ensureUploadsDirectory();
    $storedName = bin2hex(random_bytes(16)) . '.mp4';
    $path = $storage->destinationForStoredName($storedName);
    file_put_contents($path, 'not a real mp4');
    $id = $repository->create($userId, [
        'original_name' => 'bad-transcription.mp4',
        'stored_name' => $storedName,
        'storage_path' => $storage->relativePathForStoredName($storedName),
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => filesize($path) ?: 1,
        'status' => 'pending_metadata',
        'metadata_status' => 'ready',
    ]);
    Connection::get()->prepare(
        "UPDATE video_files
         SET duration_seconds = 3,
             width = 64,
             height = 64,
             fps = 5,
             video_codec = 'h264',
             audio_codec = 'aac',
             container_format = 'mov,mp4',
             bitrate = 1,
             metadata_status = 'ready',
             analyzed_at = UTC_TIMESTAMP()
         WHERE id = :id"
    )->execute(['id' => $id]);

    return [$id, $path];
}

function video_transcriptions_create_completed_export(VideoExportJobRepository $repository, VideoStorage $storage, int $userId, int $videoId, string $sourcePath, string $name, string $status = 'completed', bool $withFile = true): array
{
    $storedName = 'export_' . bin2hex(random_bytes(16)) . '.mp4';
    $jobId = $repository->createWithSegments($userId, $videoId, $name, $storedName, 3.0, [
        ['source_start_seconds' => 0.0, 'source_end_seconds' => 3.0],
    ]);
    $path = $storage->exportsDirectory() . DIRECTORY_SEPARATOR . $storedName;

    if ($withFile) {
        copy($sourcePath, $path);
    }

    $outputPath = 'storage/video/exports/' . $storedName;
    $sqlStatus = in_array($status, ['completed', 'processing', 'failed', 'pending'], true) ? $status : 'completed';
    $statement = Connection::get()->prepare(
        "UPDATE video_export_jobs
         SET status = :status,
             output_path = :output_path,
             output_size_bytes = :output_size_bytes,
             progress_percent = :progress_percent,
             output_duration_seconds = :duration,
             completed_at = CASE WHEN :completed_at_flag = 1 THEN UTC_TIMESTAMP() ELSE completed_at END,
             expires_at = CASE WHEN :expires_at_flag = 1 THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY) ELSE expires_at END
         WHERE id = :id"
    );
    $statement->execute([
        'status' => $sqlStatus,
        'output_path' => $withFile ? $outputPath : null,
        'output_size_bytes' => $withFile && is_file($path) ? filesize($path) : null,
        'progress_percent' => $sqlStatus === 'completed' ? 100 : 0,
        'duration' => $sqlStatus === 'completed' ? '3.000' : null,
        'completed_at_flag' => $sqlStatus === 'completed' ? 1 : 0,
        'expires_at_flag' => $sqlStatus === 'completed' ? 1 : 0,
        'id' => $jobId,
    ]);

    return [$jobId, $path];
}

try {
    foreach ([
        '202608080001_create_auth_tables.php',
        '202608080009_create_video_files.php',
        '202608080010_add_video_file_metadata.php',
        '202608080013_create_video_export_jobs.php',
        '202608080014_add_video_export_progress.php',
        '202608080015_create_video_transcriptions.php',
        '202608080016_add_export_sources_to_video_transcriptions.php',
    ] as $migrationFile) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $migrationFile;
        $migration($pdo);
    }

    video_transcriptions_assert(video_transcriptions_table_exists($pdo, 'video_transcriptions'), 'video_transcriptions table was not created.');
    video_transcriptions_assert(video_transcriptions_table_exists($pdo, 'video_transcription_segments'), 'video_transcription_segments table was not created.');
    video_transcriptions_assert($whisper->binaryIsExecutable($whisper->ffmpegBinary()), 'FFmpeg is not available for transcription.');
    video_transcriptions_assert($whisper->binaryIsExecutable($whisper->ffprobeBinary()), 'FFprobe is not available for transcription.');
    video_transcriptions_assert($whisper->binaryIsExecutable($whisper->whisperBinary()), 'whisper.cpp is not available for transcription.');
    video_transcriptions_assert($whisper->modelIsInstalled(), 'Configured Whisper model is not installed.');
    $storage->ensureExportDirectories();

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    [$videoId, $path] = video_transcriptions_create_voice_video($videoRepository, $storage, $videoConfig, $userId, 'voice-auto.mp4', 'hello today we test local transcription');
    $createdUploadFiles[] = $path;
    [$esVideoId, $esPath] = video_transcriptions_create_voice_video($videoRepository, $storage, $videoConfig, $userId, 'voice-es.mp4', 'hola esto es una prueba local');
    $createdUploadFiles[] = $esPath;
    [$enVideoId, $enPath] = video_transcriptions_create_voice_video($videoRepository, $storage, $videoConfig, $userId, 'voice-en.mp4', 'hello this is another local test');
    $createdUploadFiles[] = $enPath;
    [$badVideoId, $badPath] = video_transcriptions_create_bad_video($videoRepository, $storage, $userId);
    $createdUploadFiles[] = $badPath;
    [$otherVideoId, $otherPath] = video_transcriptions_create_voice_video($videoRepository, $storage, $videoConfig, $otherUserId, 'voice-other.mp4', 'private local voice');
    $createdUploadFiles[] = $otherPath;

    $originalHash = hash_file('sha256', $path);
    $originalSize = filesize($path);

    video_transcriptions_login($username, $password, $cookieFile);
    video_transcriptions_login($otherUsername, $otherPassword, $otherCookieFile);
    $detailPage = video_transcriptions_request('http://web/index.php?section=video-editor&id=' . $videoId, 'GET', null, $cookieFile);
    video_transcriptions_assert($detailPage['status'] === 200 && str_contains($detailPage['body'], 'Transcripciones') && str_contains($detailPage['body'], 'data-video-transcription-list'), 'Video detail did not expose transcription UI.');
    video_transcriptions_assert(str_contains($detailPage['body'], 'data-video-transcription-open') && str_contains($detailPage['body'], 'data-video-transcription-confirm') && str_contains($detailPage['body'], 'data-transcription-source-type="video"'), 'Video detail did not expose a working transcription modal trigger.');
    video_transcriptions_assert(str_contains($detailPage['body'], '/assets/js/app.js?v='), 'Frontend script is not cache-busted for transcription handlers.');
    $csrf = video_transcriptions_csrf($detailPage['body']);

    $noCsrf = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'auto'], $cookieFile);
    video_transcriptions_assert($noCsrf['status'] === 403, 'Creating a transcription did not require CSRF.');

    $invalidLanguage = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'es; touch nope'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_transcriptions_assert($invalidLanguage['status'] === 422, 'Invalid transcription language was accepted.');

    $foreignCreate = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $otherVideoId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'auto'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_transcriptions_assert($foreignCreate['status'] === 422, 'User created transcription for another user video.');

    $create = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'auto'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_transcriptions_assert($create['status'] === 201 && (int) ($create['json']['data']['id'] ?? 0) > 0, 'Transcription job was not created.');
    $jobId = (int) $create['json']['data']['id'];
    $job = $transcriptionRepository->findForUser($userId, $jobId);
    video_transcriptions_assert(is_array($job) && $job['status'] === 'pending' && (float) $job['progress_percent'] === 0.0 && $job['requested_language'] === 'auto', 'Pending transcription did not persist expected initial state.');
    video_transcriptions_assert($job['source_type'] === 'video' && (int) $job['video_id'] === $videoId && $job['export_job_id'] === null && (int) $job['source_video_id'] === $videoId, 'Original transcription did not persist exactly one video source.');
    video_transcriptions_assert((string) $job['model'] === $whisper->configuredModelName(), 'Transcription job did not record configured model name.');
    $originalSource = $sourceResolver->videoForUser($userId, $videoId);
    video_transcriptions_assert(str_starts_with((string) realpath($originalSource['absolute_path']), (string) realpath($storage->uploadsDirectory()) . DIRECTORY_SEPARATOR), 'Original transcription source was not limited to uploads.');

    $duplicate = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $videoId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'auto'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_transcriptions_assert($duplicate['status'] === 422 && str_contains((string) ($duplicate['json']['error'] ?? ''), 'ya se esta transcribiendo'), 'Duplicate active transcription was not rejected.');

    $ownList = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $videoId, 'GET', null, $cookieFile);
    video_transcriptions_assert($ownList['status'] === 200 && count($ownList['json']['data']['items'] ?? []) === 1, 'Polling endpoint did not return own transcription.');
    $foreignList = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $videoId, 'GET', null, $otherCookieFile);
    video_transcriptions_assert($foreignList['status'] >= 400, 'Polling endpoint exposed another user transcription.');

    $workerOutput = video_transcriptions_run_process(['/usr/local/bin/php', 'workers/process-video-transcriptions.php'], 'Transcription worker failed.');
    video_transcriptions_assert(str_contains($workerOutput, 'Processed: 1') && str_contains($workerOutput, 'Completed: 1'), 'Transcription worker did not process one real job.');
    $completed = $transcriptionRepository->findForUser($userId, $jobId);
    video_transcriptions_assert(is_array($completed) && $completed['status'] === 'completed' && (float) $completed['progress_percent'] === 100.0, 'Completed transcription did not persist 100 percent.');
    video_transcriptions_assert(trim((string) ($completed['full_text'] ?? '')) !== '', 'Completed transcription full_text is empty.');
    $segments = $transcriptionRepository->listSegments($jobId);
    video_transcriptions_assert($segments !== [], 'Completed transcription did not persist segments.');

    $lastStart = -1.0;

    foreach ($segments as $index => $segment) {
        video_transcriptions_assert((int) $segment['segment_index'] === $index, 'Segment indexes are not ordered.');
        video_transcriptions_assert((float) $segment['start_seconds'] >= 0.0 && (float) $segment['end_seconds'] > (float) $segment['start_seconds'], 'Segment timestamps are invalid.');
        video_transcriptions_assert((float) $segment['start_seconds'] >= $lastStart, 'Segments are not sorted by time.');
        video_transcriptions_assert(preg_match('//u', (string) $segment['text']) === 1 && trim((string) $segment['text']) !== '', 'Segment text is not valid UTF-8.');
        $lastStart = (float) $segment['start_seconds'];
    }

    video_transcriptions_assert(glob($storage->tempDirectory() . '/transcription_' . $jobId . '_*') === [], 'Real transcription left temporary files behind.');
    clearstatcache(true, $path);
    video_transcriptions_assert(is_file($path) && hash_file('sha256', $path) === $originalHash && filesize($path) === $originalSize, 'Real transcription changed original video.');

    [$exportJobId, $exportPath] = video_transcriptions_create_completed_export($exportRepository, $storage, $userId, $videoId, $path, 'voice-auto-export.mp4');
    $createdExportFiles[] = $exportPath;
    $exportHash = hash_file('sha256', $exportPath);
    $exportSize = filesize($exportPath);
    $exportSource = $sourceResolver->exportForUser($userId, $exportJobId);
    video_transcriptions_assert(str_starts_with((string) realpath($exportSource['absolute_path']), (string) realpath($storage->exportsDirectory()) . DIRECTORY_SEPARATOR), 'Export transcription source was not limited to exports.');
    [$otherExportJobId, $otherExportPath] = video_transcriptions_create_completed_export($exportRepository, $storage, $otherUserId, $otherVideoId, $otherPath, 'foreign-export.mp4');
    $createdExportFiles[] = $otherExportPath;
    [$pendingExportJobId, $pendingExportPath] = video_transcriptions_create_completed_export($exportRepository, $storage, $userId, $videoId, $path, 'pending-export.mp4', 'pending');
    [$processingExportJobId, $processingExportPath] = video_transcriptions_create_completed_export($exportRepository, $storage, $userId, $videoId, $path, 'processing-export.mp4', 'processing');
    [$failedExportJobId, $failedExportPath] = video_transcriptions_create_completed_export($exportRepository, $storage, $userId, $videoId, $path, 'failed-export.mp4', 'failed');
    [$missingExportJobId, $missingExportPath] = video_transcriptions_create_completed_export($exportRepository, $storage, $userId, $videoId, $path, 'missing-export.mp4', 'completed', false);

    foreach ([$pendingExportPath, $processingExportPath, $failedExportPath, $missingExportPath] as $unusedPath) {
        if (is_file($unusedPath)) {
            $createdExportFiles[] = $unusedPath;
        }
    }

    $exportCreate = video_transcriptions_request('http://web/api/video/transcriptions.php?export_job_id=' . $exportJobId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'auto'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_transcriptions_assert($exportCreate['status'] === 201 && (int) ($exportCreate['json']['data']['id'] ?? 0) > 0, 'Completed export transcription job was not created.');
    $exportTranscriptionId = (int) $exportCreate['json']['data']['id'];
    $exportTranscription = $transcriptionRepository->findForUser($userId, $exportTranscriptionId);
    video_transcriptions_assert(is_array($exportTranscription) && $exportTranscription['source_type'] === 'export' && $exportTranscription['video_id'] === null && (int) $exportTranscription['export_job_id'] === $exportJobId && (int) $exportTranscription['source_video_id'] === $videoId, 'Export transcription did not persist exactly one export source.');
    $duplicateExport = video_transcriptions_request('http://web/api/video/transcriptions.php?export_job_id=' . $exportJobId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'auto'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_transcriptions_assert($duplicateExport['status'] === 422 && str_contains((string) ($duplicateExport['json']['error'] ?? ''), 'ya se esta transcribiendo'), 'Duplicate active export transcription was not rejected.');

    foreach ([
        $otherExportJobId => 'foreign completed export',
        $pendingExportJobId => 'pending export',
        $processingExportJobId => 'processing export',
        $failedExportJobId => 'failed export',
        $missingExportJobId => 'missing export file',
    ] as $badExportJobId => $label) {
        $badExportCreate = video_transcriptions_request('http://web/api/video/transcriptions.php?export_job_id=' . $badExportJobId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'auto'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
        video_transcriptions_assert($badExportCreate['status'] === 422, 'Rejected source was accepted: ' . $label . '.');
    }

    $exportWorkerOutput = video_transcriptions_run_process(['/usr/local/bin/php', 'workers/process-video-transcriptions.php'], 'Transcription worker failed on completed export.');
    video_transcriptions_assert(str_contains($exportWorkerOutput, 'Processed: 1') && str_contains($exportWorkerOutput, 'Completed: 1'), 'Transcription worker did not process one export job.');
    $exportCompleted = $transcriptionRepository->findForUser($userId, $exportTranscriptionId);
    video_transcriptions_assert(is_array($exportCompleted) && $exportCompleted['status'] === 'completed' && trim((string) ($exportCompleted['full_text'] ?? '')) !== '', 'Export transcription did not complete with text.');
    clearstatcache(true, $exportPath);
    video_transcriptions_assert(is_file($exportPath) && hash_file('sha256', $exportPath) === $exportHash && filesize($exportPath) === $exportSize, 'Export file changed during transcription.');
    $listAfterExport = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $videoId, 'GET', null, $cookieFile);
    video_transcriptions_assert($listAfterExport['status'] === 200 && count($listAfterExport['json']['data']['items'] ?? []) >= 2, 'Polling endpoint did not include export transcription for the parent video.');

    if (is_file($exportPath)) {
        unlink($exportPath);
    }

    $exportRepository->deleteForUser($userId, $exportJobId);
    $exportAfterDelete = $transcriptionRepository->findForUser($userId, $exportTranscriptionId);
    video_transcriptions_assert(is_array($exportAfterDelete) && $exportAfterDelete['status'] === 'completed', 'Deleting an exported MP4 removed completed transcription history.');

    $createEs = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $esVideoId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'es'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    $createEn = video_transcriptions_request('http://web/api/video/transcriptions.php?video_id=' . $enVideoId . '&action=create', 'POST', ['action' => 'create', 'requested_language' => 'en'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_transcriptions_assert($createEs['status'] === 201 && $createEn['status'] === 201, 'Allowed transcription languages es/en were not accepted.');
    $esJobId = (int) $createEs['json']['data']['id'];
    $enJobId = (int) $createEn['json']['data']['id'];
    $reserved = $transcriptionRepository->reserveNextPending();
    video_transcriptions_assert(is_array($reserved) && (int) $reserved['id'] === $esJobId && $reserved['status'] === 'processing' && (float) $reserved['progress_percent'] === 1.0, 'Worker did not claim pending transcription atomically.');
    video_transcriptions_assert($transcriptionRepository->reserveNextPending() === null, 'Worker claimed another transcription while one was processing.');
    $transcriptionRepository->updateProgress($esJobId, 35.0);
    $transcriptionRepository->markFailed($esJobId, 'controlled failure');
    $failedEs = $transcriptionRepository->findForUser($userId, $esJobId);
    video_transcriptions_assert(is_array($failedEs) && $failedEs['status'] === 'failed' && abs((float) $failedEs['progress_percent'] - 35.0) < 0.001, 'Failed transcription did not keep last real percent.');
    $reservedEn = $transcriptionRepository->reserveNextPending();
    video_transcriptions_assert(is_array($reservedEn) && (int) $reservedEn['id'] === $enJobId, 'Pending English transcription was not claimable after previous processing ended.');
    $transcriptionRepository->markFailed($enJobId, 'controlled failure');

    [$badHash, $badSize] = [hash_file('sha256', $badPath), filesize($badPath)];
    $badCreate = $transcriptionService->createForVideo($userId, $badVideoId, 'auto');
    $badJobId = (int) $badCreate['id'];
    $badWorkerOutput = video_transcriptions_run_process(['/usr/local/bin/php', 'workers/process-video-transcriptions.php'], 'Transcription worker failed on bad video.');
    video_transcriptions_assert(str_contains($badWorkerOutput, 'Failed: 1'), 'Bad transcription did not fail in worker.');
    $badJob = $transcriptionRepository->findForUser($userId, $badJobId);
    video_transcriptions_assert(is_array($badJob) && $badJob['status'] === 'failed' && (float) $badJob['progress_percent'] < 100.0, 'Bad transcription was not marked failed conservatively.');
    video_transcriptions_assert(glob($storage->tempDirectory() . '/transcription_' . $badJobId . '_*') === [], 'Failed transcription left temporary files behind.');
    clearstatcache(true, $badPath);
    video_transcriptions_assert(is_file($badPath) && hash_file('sha256', $badPath) === $badHash && filesize($badPath) === $badSize, 'Failed transcription changed original video.');

    $utfJobId = $transcriptionRepository->createForVideo($userId, $esVideoId, 'voice-es.mp4', 'es', 'base');
    $utfReserved = $transcriptionRepository->reserveNextPending();
    video_transcriptions_assert(is_array($utfReserved) && (int) $utfReserved['id'] === $utfJobId, 'UTF-8 fixture transcription was not claimable.');
    $spanishText = '¿Hola! año nuevo, canción, pingüino, ¡listo!';
    $transcriptionRepository->markCompleted($utfJobId, null, 'base', $spanishText, [
        ['start_seconds' => 0.0, 'end_seconds' => 1.2, 'text' => '¿Hola! año nuevo'],
        ['start_seconds' => 1.2, 'end_seconds' => 2.4, 'text' => 'canción, pingüino, ¡listo!'],
    ]);
    $utfCompleted = $transcriptionRepository->findForUser($userId, $utfJobId);
    video_transcriptions_assert(is_array($utfCompleted) && $utfCompleted['full_text'] === $spanishText, 'Spanish UTF-8 full_text was not preserved.');

    $foreignDelete = video_transcriptions_request('http://web/api/video/transcriptions.php?id=' . $utfJobId . '&action=delete', 'POST', ['action' => 'delete'], $otherCookieFile, ['X-CSRF-Token: ' . video_transcriptions_csrf(video_transcriptions_request('http://web/index.php?section=video-editor&id=' . $otherVideoId, 'GET', null, $otherCookieFile)['body'])]);
    video_transcriptions_assert($foreignDelete['status'] === 404, 'Another user deleted a transcription.');
    $deleteOwn = video_transcriptions_request('http://web/api/video/transcriptions.php?id=' . $utfJobId . '&action=delete', 'POST', ['action' => 'delete'], $cookieFile, ['X-CSRF-Token: ' . $csrf]);
    video_transcriptions_assert($deleteOwn['status'] === 200 && $transcriptionRepository->findForUser($userId, $utfJobId) === null && $transcriptionRepository->listSegments($utfJobId) === [], 'Own transcription was not deleted with segments.');
    video_transcriptions_assert(is_file($esPath), 'Deleting transcription deleted the video.');

    $oldTemp = $storage->tempDirectory() . '/transcription_999999_' . bin2hex(random_bytes(8)) . '.wav';
    file_put_contents($oldTemp, 'old temp');
    touch($oldTemp, time() - 90000);
    $createdTempFiles[] = $oldTemp;
    $cleanupJobId = $transcriptionRepository->createForVideo($userId, $enVideoId, 'voice-en.mp4', 'en', 'base');
    $cleanupReserved = $transcriptionRepository->reserveNextPending();
    video_transcriptions_assert(is_array($cleanupReserved) && (int) $cleanupReserved['id'] === $cleanupJobId, 'Cleanup processing transcription was not claimable.');
    $processingTemp = $storage->tempDirectory() . '/transcription_' . $cleanupJobId . '_' . bin2hex(random_bytes(8)) . '.json';
    file_put_contents($processingTemp, '{}');
    touch($processingTemp, time() - 90000);
    $createdTempFiles[] = $processingTemp;
    $cleanup = new VideoCleanupService($videoConfig, $exportRepository, $storage, $transcriptionRepository);
    $cleanupSummary = $cleanup->cleanup();
    video_transcriptions_assert($cleanupSummary['temporary_files_removed'] >= 1 && !is_file($oldTemp), 'Cleanup did not remove old transcription temporary file.');
    video_transcriptions_assert(is_file($processingTemp), 'Cleanup removed temp file for a processing transcription.');
    $transcriptionRepository->markFailed($cleanupJobId, 'cleanup done');

    $serviceSource = file_get_contents(dirname(__DIR__, 2) . '/modules/Video/WhisperService.php');
    $processorSource = file_get_contents(dirname(__DIR__, 2) . '/modules/Video/VideoTranscriptionProcessor.php');
    $jsSource = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    $crontab = file_get_contents(dirname(__DIR__, 2) . '/docker/worker/crontab');
    video_transcriptions_assert(is_string($serviceSource) && str_contains($serviceSource, "'-ac'") && str_contains($serviceSource, "'1'") && str_contains($serviceSource, "'-ar'") && str_contains($serviceSource, "'16000'") && str_contains($serviceSource, "'pcm_s16le'"), 'Audio extraction is not configured as 16k mono PCM WAV.');
    video_transcriptions_assert(is_string($serviceSource) && str_contains($serviceSource, 'proc_open($command') && !str_contains($serviceSource, 'shell_exec') && !str_contains($serviceSource, 'system('), 'Whisper execution is not using controlled arguments.');
    video_transcriptions_assert(is_string($processorSource) && str_contains($processorSource, 'markCompleted') && str_contains($processorSource, 'markFailed') === false, 'Processor should leave failure state handling to the worker.');
    video_transcriptions_assert(is_string($jsSource) && str_contains($jsSource, 'startTranscriptionPolling') && str_contains($jsSource, '3000') && str_contains($jsSource, 'stopTranscriptionPolling'), 'Transcription polling is not wired at 3 seconds.');
    video_transcriptions_assert(is_string($crontab) && str_contains($crontab, 'process-reminders.php') && str_contains($crontab, 'process-video-metadata.php') && str_contains($crontab, 'process-video-exports.php') && str_contains($crontab, 'process-video-transcriptions.php') && str_contains($crontab, 'cleanup-video-files.php'), 'Worker crontab lost or omitted expected jobs.');

    echo "Video transcriptions: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video transcriptions: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
} finally {
    foreach (array_merge($createdTempFiles, $createdExportFiles, $createdUploadFiles) as $file) {
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
