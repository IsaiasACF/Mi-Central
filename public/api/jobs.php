<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$basePath = dirname(__DIR__, 2);
$cronSecret = getenv('CRON_SECRET') ?: '';
$manualSecret = getenv('JOB_SECRET') ?: '';
$job = $_GET['job'] ?? $_POST['job'] ?? '';
$allowedJobs = [
    'process-reminders',
    'process-recurring-expenses',
    'process-expense-notifications',
    'process-notification-activity',
    'process-discount-collectors',
];

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';

if (is_string($authorization) && preg_match('/^Bearer\s+(.*)$/i', trim($authorization), $matches) === 1) {
    $token = trim($matches[1]);
}

$manualSecretFromQuery = is_string($_GET['secret'] ?? null) ? $_GET['secret'] : '';
$manualSecretFromHeader = is_string($_SERVER['HTTP_X_JOB_SECRET'] ?? null) ? $_SERVER['HTTP_X_JOB_SECRET'] : '';

$isAuthorizedForCron = $cronSecret !== '' && $token !== '' && hash_equals($cronSecret, $token);
$isAuthorizedForManual = (
    $manualSecret !== '' && (
        hash_equals($manualSecret, $token)
        || (hash_equals($manualSecret, $manualSecretFromQuery) && $manualSecretFromQuery !== '')
        || (hash_equals($manualSecret, $manualSecretFromHeader) && $manualSecretFromHeader !== '')
    )
);

if (!$isAuthorizedForCron && !$isAuthorizedForManual) {
    // Diagnostico temporal: registrar presencia, longitudes, coincidencia y prefijos SHA-256.
    error_log(sprintf(
        'JOB_SECRET configurado: %s; strlen(JOB_SECRET): %d; X-Job-Secret recibido: %s; strlen(X-Job-Secret): %d; hash_equals: %s; sha256(JOB_SECRET)[0:12]: %s; sha256(X-Job-Secret)[0:12]: %s',
        $manualSecret !== '' ? 'sí' : 'no',
        strlen($manualSecret),
        $manualSecretFromHeader !== '' ? 'sí' : 'no',
        strlen($manualSecretFromHeader),
        hash_equals($manualSecret, $manualSecretFromHeader) ? 'sí' : 'no',
        substr(hash('sha256', $manualSecret), 0, 12),
        substr(hash('sha256', $manualSecretFromHeader), 0, 12)
    ));
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid Authorization token.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

if (!is_string($job) || !in_array($job, $allowedJobs, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown job.', 'allowed_jobs' => $allowedJobs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

$scriptPath = $basePath . '/workers/' . $job . '.php';

if (!is_file($scriptPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Job script not found.', 'job' => $job], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

$phpBinary = (string) PHP_BINARY;

if ($phpBinary === '' || !is_file($phpBinary) || !is_executable($phpBinary)) {
    $phpBinary = '/usr/local/bin/php';
}

if (!is_file($phpBinary) || !is_executable($phpBinary)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'PHP CLI binary not found or not executable (PHP_BINARY and /usr/local/bin/php).', 'job' => $job], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

$command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
$output = [];
$code = 0;
exec($command, $output, $code);

$body = [
    'ok' => $code === 0,
    'job' => $job,
    'exit_code' => $code,
    'output' => trim(implode(PHP_EOL, $output)),
];

http_response_code($code === 0 ? 200 : 500);
echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit($code === 0 ? 0 : 1);
