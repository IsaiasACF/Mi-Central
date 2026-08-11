<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Video\TranscriptionExportService;
use Modules\Video\VideoRepository;
use Modules\Video\VideoTranscriptionRepository;
use Modules\Video\VideoValidationException;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = Connection::get();
$auth = new AuthService($pdo);
$videoRepository = new VideoRepository($pdo);
$transcriptionRepository = new VideoTranscriptionRepository($pdo);
$exportService = new TranscriptionExportService($transcriptionRepository);
$username = 'test_video_transcription_exports_' . bin2hex(random_bytes(4));
$otherUsername = 'test_video_transcription_exports_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-transcription-export-');
$otherCookieFile = tempnam(sys_get_temp_dir(), 'mi-central-transcription-export-other-');
$exitCode = 1;

function video_transcription_exports_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function video_transcription_exports_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
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

    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function video_transcription_exports_form_request(string $url, array $postFields, string $cookieFile): array
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

function video_transcription_exports_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) === 1 || preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) === 1) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    throw new RuntimeException('CSRF token was not found.');
}

function video_transcription_exports_login(string $username, string $password, string $cookieFile): void
{
    $loginPage = video_transcription_exports_request('http://web/login.php', 'GET', null, $cookieFile);
    $login = video_transcription_exports_form_request('http://web/login.php', [
        'csrf_token' => video_transcription_exports_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);

    video_transcription_exports_assert($login['status'] === 302, 'Test user could not log in.');
}

function video_transcription_exports_create_video(VideoRepository $repository, int $userId, string $originalName): int
{
    $storedName = bin2hex(random_bytes(16)) . '.mp4';
    $id = $repository->create($userId, [
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'storage_path' => 'storage/video/uploads/' . $storedName,
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 1234,
        'status' => 'pending_metadata',
        'metadata_status' => 'ready',
    ]);
    Connection::get()->prepare(
        "UPDATE video_files
         SET duration_seconds = 3665,
             width = 64,
             height = 64,
             fps = 5,
             video_codec = 'h264',
             audio_codec = 'aac',
             container_format = 'mov,mp4',
             bitrate = 1234,
             metadata_status = 'ready',
             analyzed_at = UTC_TIMESTAMP()
         WHERE id = :id"
    )->execute(['id' => $id]);

    return $id;
}

function video_transcription_exports_completed(VideoTranscriptionRepository $repository, int $userId, int $videoId, array $segments, string $fullText = ''): int
{
    $id = $repository->createForVideo($userId, $videoId, 'Reunion Ellison Parte 2.mp4', 'es', 'base');
    $reserved = $repository->reserveNextPending();

    video_transcription_exports_assert(is_array($reserved) && (int) $reserved['id'] === $id, 'Could not reserve transcription fixture.');
    $repository->markCompleted($id, null, 'base', $fullText, $segments);

    return $id;
}

function video_transcription_exports_pending(VideoTranscriptionRepository $repository, int $userId, int $videoId): int
{
    return $repository->createForVideo($userId, $videoId, 'Pendiente.mp4', 'auto', 'base');
}

function video_transcription_exports_count_rows(PDO $pdo): array
{
    return [
        'transcriptions' => (int) $pdo->query('SELECT COUNT(*) FROM video_transcriptions')->fetchColumn(),
        'segments' => (int) $pdo->query('SELECT COUNT(*) FROM video_transcription_segments')->fetchColumn(),
    ];
}

function video_transcription_exports_storage_files(): array
{
    $base = dirname(__DIR__, 2) . '/storage/video';
    $files = [];

    foreach (['temp', 'exports', 'transcriptions'] as $directory) {
        $path = $base . '/' . $directory;

        if (!is_dir($path)) {
            continue;
        }

        foreach (glob($path . '/*transcripcion*') ?: [] as $file) {
            $files[] = $file;
        }

        foreach (glob($path . '/*subtitulos*') ?: [] as $file) {
            $files[] = $file;
        }
    }

    sort($files);

    return $files;
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

    video_transcription_exports_assert($exportService->secondsToSrtTimestamp(4.2) === '00:00:04,200', 'SRT timestamp for 4.2 is invalid.');
    video_transcription_exports_assert($exportService->secondsToVttTimestamp(4.2) === '00:00:04.200', 'VTT timestamp for 4.2 is invalid.');
    video_transcription_exports_assert($exportService->secondsToSrtTimestamp(65.987) === '00:01:05,987', 'SRT timestamp for 65.987 is invalid.');
    video_transcription_exports_assert($exportService->secondsToVttTimestamp(65.987) === '00:01:05.987', 'VTT timestamp for 65.987 is invalid.');
    video_transcription_exports_assert($exportService->secondsToSrtTimestamp(3661.001) === '01:01:01,001', 'SRT timestamp over one hour is invalid.');
    video_transcription_exports_assert($exportService->secondsToVttTimestamp(3661.001) === '01:01:01.001', 'VTT timestamp over one hour is invalid.');
    video_transcription_exports_assert($exportService->secondsToSrtTimestamp(59.9996) === '00:01:00,000', 'Millisecond carry is invalid.');
    video_transcription_exports_assert($exportService->downloadName('Reunion Ellison Parte 2.mp4', 'txt') === 'Reunion_Ellison_Parte_2_transcripcion.txt', 'TXT download name is invalid.');
    video_transcription_exports_assert($exportService->downloadName('../Reunion Ellison Parte 2.mp4', 'txt_timestamps') === 'Reunion_Ellison_Parte_2_transcripcion_tiempos.txt', 'Timestamp TXT download name is unsafe.');
    video_transcription_exports_assert($exportService->downloadName('Reunion Ellison Parte 2.mp4', 'srt') === 'Reunion_Ellison_Parte_2_subtitulos.srt', 'SRT download name is invalid.');
    video_transcription_exports_assert($exportService->downloadName('Reunion Ellison Parte 2.mp4', 'vtt') === 'Reunion_Ellison_Parte_2_subtitulos.vtt', 'VTT download name is invalid.');

    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $videoId = video_transcription_exports_create_video($videoRepository, $userId, 'Reunion Ellison Parte 2.mp4');
    $otherVideoId = video_transcription_exports_create_video($videoRepository, $otherUserId, 'Video ajeno.mp4');
    $pendingVideoId = video_transcription_exports_create_video($videoRepository, $userId, 'Pendiente.mp4');
    $fallbackVideoId = video_transcription_exports_create_video($videoRepository, $userId, 'Solo texto.mp4');
    $segments = [
        ['start_seconds' => 65.987, 'end_seconds' => 70.111, 'text' => 'Primero analizaremos los resultados.'],
        ['start_seconds' => 4.2, 'end_seconds' => 9.1, 'text' => 'Hola, hoy vamos a revisar el proyecto.'],
        ['start_seconds' => 3661.001, 'end_seconds' => 3665.999, 'text' => 'Luego veremos los siguientes pasos con ñ, ¿dudas? ¡listo!'],
    ];
    $transcriptionId = video_transcription_exports_completed($transcriptionRepository, $userId, $videoId, $segments, 'full_text should not override segments');
    $otherTranscriptionId = video_transcription_exports_completed($transcriptionRepository, $otherUserId, $otherVideoId, $segments, 'otro texto');
    $fallbackTranscriptionId = video_transcription_exports_completed($transcriptionRepository, $userId, $fallbackVideoId, [], 'Texto limpio desde full_text con acentos: canción y niño.');
    $pendingTranscriptionId = video_transcription_exports_pending($transcriptionRepository, $userId, $pendingVideoId);

    video_transcription_exports_login($username, $password, $cookieFile);
    video_transcription_exports_login($otherUsername, $otherPassword, $otherCookieFile);
    $filesBefore = video_transcription_exports_storage_files();
    $rowsBefore = video_transcription_exports_count_rows($pdo);

    $txt = $exportService->export($userId, $transcriptionId, 'txt');
    video_transcription_exports_assert($txt['content'] === "Hola, hoy vamos a revisar el proyecto.\nPrimero analizaremos los resultados.\nLuego veremos los siguientes pasos con ñ, ¿dudas? ¡listo!\n", 'Clean TXT content is invalid or unordered.');
    $timed = $exportService->export($userId, $transcriptionId, 'txt_timestamps');
    video_transcription_exports_assert($timed['content'] === "[00:00:04] Hola, hoy vamos a revisar el proyecto.\n[00:01:05] Primero analizaremos los resultados.\n[01:01:01] Luego veremos los siguientes pasos con ñ, ¿dudas? ¡listo!\n", 'Timestamp TXT content is invalid.');
    $srt = $exportService->export($userId, $transcriptionId, 'srt');
    video_transcription_exports_assert($srt['content'] === "1\n00:00:04,200 --> 00:00:09,100\nHola, hoy vamos a revisar el proyecto.\n\n2\n00:01:05,987 --> 00:01:10,111\nPrimero analizaremos los resultados.\n\n3\n01:01:01,001 --> 01:01:05,999\nLuego veremos los siguientes pasos con ñ, ¿dudas? ¡listo!\n", 'SRT content is invalid.');
    $vtt = $exportService->export($userId, $transcriptionId, 'vtt');
    video_transcription_exports_assert($vtt['content'] === "WEBVTT\n\n00:00:04.200 --> 00:00:09.100\nHola, hoy vamos a revisar el proyecto.\n\n00:01:05.987 --> 00:01:10.111\nPrimero analizaremos los resultados.\n\n01:01:01.001 --> 01:01:05.999\nLuego veremos los siguientes pasos con ñ, ¿dudas? ¡listo!\n", 'VTT content is invalid.');
    $fallbackTxt = $exportService->export($userId, $fallbackTranscriptionId, 'txt');
    video_transcription_exports_assert($fallbackTxt['content'] === "Texto limpio desde full_text con acentos: canción y niño.\n", 'TXT did not fall back to full_text without segments.');

    foreach (['srt', 'vtt', 'txt_timestamps'] as $format) {
        try {
            $exportService->export($userId, $fallbackTranscriptionId, $format);
            video_transcription_exports_assert(false, $format . ' without segments was allowed.');
        } catch (VideoValidationException $exception) {
            video_transcription_exports_assert(str_contains($exception->getMessage(), 'segmentos temporales'), $format . ' failed with wrong error.');
        }
    }

    try {
        $exportService->export($userId, $pendingTranscriptionId, 'txt');
        video_transcription_exports_assert(false, 'Pending transcription was exportable.');
    } catch (VideoValidationException $exception) {
        video_transcription_exports_assert(str_contains($exception->getMessage(), 'completada'), 'Pending transcription failed with wrong error.');
    }

    try {
        $exportService->export($userId, $transcriptionId, 'html');
        video_transcription_exports_assert(false, 'Invalid format was accepted.');
    } catch (VideoValidationException $exception) {
        video_transcription_exports_assert(str_contains($exception->getMessage(), 'Formato'), 'Invalid format failed with wrong error.');
    }

    try {
        $exportService->export($userId, $otherTranscriptionId, 'txt');
        video_transcription_exports_assert(false, 'Foreign transcription was exportable.');
    } catch (VideoValidationException $exception) {
        video_transcription_exports_assert(str_contains($exception->getMessage(), 'no encontrada'), 'Foreign transcription failed with wrong error.');
    }

    $downloadTxt = video_transcription_exports_request('http://web/video/transcription/download.php?id=' . $transcriptionId . '&format=txt', 'GET', null, $cookieFile);
    video_transcription_exports_assert($downloadTxt['status'] === 200 && str_contains($downloadTxt['headers'], 'Content-Type: text/plain; charset=UTF-8') && str_contains($downloadTxt['headers'], 'Content-Disposition: attachment; filename="Reunion_Ellison_Parte_2_transcripcion.txt"') && $downloadTxt['body'] === $txt['content'], 'TXT download response is invalid.');
    $downloadTimed = video_transcription_exports_request('http://web/video/transcription/download.php?id=' . $transcriptionId . '&format=txt_timestamps', 'GET', null, $cookieFile);
    video_transcription_exports_assert($downloadTimed['status'] === 200 && str_contains($downloadTimed['headers'], 'filename="Reunion_Ellison_Parte_2_transcripcion_tiempos.txt"') && $downloadTimed['body'] === $timed['content'], 'Timestamp TXT download response is invalid.');
    $downloadSrt = video_transcription_exports_request('http://web/video/transcription/download.php?id=' . $transcriptionId . '&format=srt', 'GET', null, $cookieFile);
    video_transcription_exports_assert($downloadSrt['status'] === 200 && str_contains($downloadSrt['headers'], 'Content-Type: application/x-subrip; charset=UTF-8') && str_contains($downloadSrt['headers'], 'filename="Reunion_Ellison_Parte_2_subtitulos.srt"') && $downloadSrt['body'] === $srt['content'], 'SRT download response is invalid.');
    $downloadVtt = video_transcription_exports_request('http://web/video/transcription/download.php?id=' . $transcriptionId . '&format=vtt', 'GET', null, $cookieFile);
    video_transcription_exports_assert($downloadVtt['status'] === 200 && str_contains($downloadVtt['headers'], 'Content-Type: text/vtt; charset=UTF-8') && str_contains($downloadVtt['headers'], 'filename="Reunion_Ellison_Parte_2_subtitulos.vtt"') && $downloadVtt['body'] === $vtt['content'], 'VTT download response is invalid.');

    $guest = video_transcription_exports_request('http://web/video/transcription/download.php?id=' . $transcriptionId . '&format=txt', 'GET');
    video_transcription_exports_assert($guest['status'] === 401, 'Unauthenticated transcription download was not rejected.');
    $foreign = video_transcription_exports_request('http://web/video/transcription/download.php?id=' . $transcriptionId . '&format=txt', 'GET', null, $otherCookieFile);
    video_transcription_exports_assert($foreign['status'] === 404, 'Foreign transcription download was not rejected.');
    $invalidFormat = video_transcription_exports_request('http://web/video/transcription/download.php?id=' . $transcriptionId . '&format=html', 'GET', null, $cookieFile);
    video_transcription_exports_assert($invalidFormat['status'] === 422, 'Invalid format download was not rejected.');
    $pendingDownload = video_transcription_exports_request('http://web/video/transcription/download.php?id=' . $pendingTranscriptionId . '&format=txt', 'GET', null, $cookieFile);
    video_transcription_exports_assert($pendingDownload['status'] === 422, 'Pending transcription download was not rejected.');
    $fallbackSrt = video_transcription_exports_request('http://web/video/transcription/download.php?id=' . $fallbackTranscriptionId . '&format=srt', 'GET', null, $cookieFile);
    video_transcription_exports_assert($fallbackSrt['status'] === 422 && str_contains($fallbackSrt['body'], 'No hay segmentos temporales'), 'SRT without segments was not rejected.');

    $detailPage = video_transcription_exports_request('http://web/index.php?section=video-editor&id=' . $videoId, 'GET', null, $cookieFile);
    video_transcription_exports_assert($detailPage['status'] === 200 && str_contains($detailPage['body'], 'Descargar TXT') && str_contains($detailPage['body'], 'TXT con tiempos') && str_contains($detailPage['body'], 'Descargar SRT') && str_contains($detailPage['body'], 'Descargar VTT') && str_contains($detailPage['body'], 'Copiar texto'), 'Completed transcription export buttons were not rendered.');
    $jsSource = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');
    video_transcription_exports_assert(is_string($jsSource) && str_contains($jsSource, 'navigator.clipboard.writeText') && str_contains($jsSource, '/video/transcription/download.php') && str_contains($jsSource, "transcriptionDownloadUrl(transcriptionId, 'txt')") && str_contains($jsSource, 'Transcripcion copiada.'), 'Copy text action is not wired safely.');

    video_transcription_exports_assert(video_transcription_exports_storage_files() === $filesBefore, 'Transcription export created permanent files.');
    video_transcription_exports_assert(video_transcription_exports_count_rows($pdo) === $rowsBefore, 'Transcription export modified database rows.');

    echo "Video transcription exports: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Video transcription exports: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
} finally {
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
