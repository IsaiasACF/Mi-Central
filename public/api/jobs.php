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

$command = escapeshellarg((string) PHP_BINARY) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
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
