<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Services\AuthService;
use Modules\Organization\NoteRepository;
use Modules\Organization\NoteService;
use Modules\Organization\SpaceRepository;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$pdo = Connection::get();
$auth = new AuthService($pdo);
$noteService = new NoteService(new NoteRepository($pdo));
$spaceRepository = new SpaceRepository($pdo);
$username = 'test_notes_' . bin2hex(random_bytes(4));
$otherUsername = 'test_notes_other_' . bin2hex(random_bytes(4));
$password = 'test-secret-' . bin2hex(random_bytes(8));
$otherPassword = 'test-secret-' . bin2hex(random_bytes(8));
$cookieFile = tempnam(sys_get_temp_dir(), 'mi-central-notes-');
$exitCode = 1;

function notes_request(string $url, string $method = 'GET', ?array $payload = null, ?string $cookieFile = null, array $headers = []): array
{
    $handle = curl_init($url);
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($body === false) {
        throw new RuntimeException('Could not encode request payload.');
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
        $headers[] = 'Content-Type: application/json';
    }

    if ($headers !== []) {
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
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

function notes_form_request(string $url, array $postFields, string $cookieFile): array
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

function notes_login_csrf(string $html): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Login CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function notes_screen_csrf(string $html): string
{
    if (preg_match('/data-csrf-token="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('Screen CSRF token was not found.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}

function notes_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $userId = $auth->createUser($username, $password);
    $otherUserId = $auth->createUser($otherUsername, $otherPassword);
    $seed = require dirname(__DIR__, 2) . '/database/seeds/202608080001_seed_organization_spaces.php';
    $seed($pdo);

    $spaceId = (int) $spaceRepository->listForUser($userId)[0]['id'];
    $otherSpaceId = (int) $spaceRepository->listForUser($otherUserId)[0]['id'];

    $note = $noteService->create($userId, [
        'title' => 'Nota Universidad',
        'content' => 'Contenido inicial',
        'space_id' => $spaceId,
    ]);
    notes_assert((int) $note['space_id'] === $spaceId, 'Note was not associated with its space.');

    $noSpaceNote = $noteService->create($userId, [
        'title' => 'Nota sin espacio',
        'content' => 'Contenido sin espacio',
    ]);
    notes_assert($noSpaceNote['space_id'] === null, 'No-space note did not keep space_id NULL.');

    $otherNote = $noteService->create($otherUserId, [
        'title' => 'Nota de otro usuario',
        'content' => 'No visible',
        'space_id' => $otherSpaceId,
    ]);
    notes_assert($noteService->get($userId, (int) $otherNote['id']) === null, 'User could read another user note.');

    $filtered = $noteService->list($userId, ['space_id' => $spaceId]);
    notes_assert(count($filtered) === 1 && $filtered[0]['title'] === 'Nota Universidad', 'Note space filter failed.');

    $updated = $noteService->update($userId, (int) $note['id'], [
        'title' => 'Nota editada',
        'content' => 'Contenido editado',
        'space_id' => '',
    ]);
    notes_assert($updated !== null && $updated['title'] === 'Nota editada' && $updated['space_id'] === null, 'Note update failed.');

    $loginPage = notes_request('http://127.0.0.1/login.php', 'GET', null, $cookieFile);
    $login = notes_form_request('http://127.0.0.1/login.php', [
        'csrf_token' => notes_login_csrf($loginPage['body']),
        'username' => $username,
        'password' => $password,
    ], $cookieFile);
    notes_assert($login['status'] === 302, 'Test user could not log in.');

    $notesPage = notes_request('http://127.0.0.1/index.php?section=organization&tab=notes&status=all', 'GET', null, $cookieFile);
    notes_assert($notesPage['status'] === 200, 'Notes page did not load.');
    notes_assert(str_contains($notesPage['body'], 'Notas') && str_contains($notesPage['body'], 'data-note-form'), 'Notes UI was not rendered.');
    notes_assert(str_contains($notesPage['body'], 'Nota editada'), 'Own note was not shown.');
    notes_assert(!str_contains($notesPage['body'], 'Nota de otro usuario'), 'Another user note was visible.');

    $csrfHeader = ['X-CSRF-Token: ' . notes_screen_csrf($notesPage['body'])];
    $create = notes_request(
        'http://127.0.0.1/api/organization/notes.php',
        'POST',
        ['title' => 'Nota desde API', 'content' => 'Texto API', 'space_id' => (string) $spaceId],
        $cookieFile,
        $csrfHeader
    );
    notes_assert($create['status'] === 201 && $create['json']['ok'] === true, 'Note API did not create a note.');
    $apiNoteId = (int) $create['json']['data']['id'];

    $list = notes_request('http://127.0.0.1/api/organization/notes.php?space_id=' . $spaceId, 'GET', null, $cookieFile);
    notes_assert($list['status'] === 200 && count($list['json']['data']) === 1, 'Note API space filter failed.');

    $patch = notes_request(
        'http://127.0.0.1/api/organization/notes.php?id=' . $apiNoteId,
        'PATCH',
        ['title' => 'Nota API editada', 'content' => 'Texto editado', 'space_id' => ''],
        $cookieFile,
        $csrfHeader
    );
    notes_assert($patch['status'] === 200 && $patch['json']['data']['space_id'] === null, 'Note API update failed.');

    $delete = notes_request('http://127.0.0.1/api/organization/notes.php?id=' . $apiNoteId, 'DELETE', [], $cookieFile, $csrfHeader);
    notes_assert($delete['status'] === 200 && $delete['json']['data']['deleted'] === true, 'Note API delete failed.');
    notes_assert($noteService->delete($userId, (int) $noSpaceNote['id']), 'Note service delete failed.');

    echo "Organization notes: OK\n";
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Organization notes: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    if (is_string($cookieFile) && is_file($cookieFile)) {
        unlink($cookieFile);
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
