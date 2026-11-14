<?php
declare(strict_types=1);

// Run against the Apache service (not PHP's development server).
function cacheResponse(string $path): array
{
    $handle = curl_init('http://localhost' . $path);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10]);
    $response = curl_exec($handle);
    if (!is_string($response)) {
        throw new RuntimeException('Cache header request failed.');
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headers = strtolower(substr($response, 0, (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE)));
    return [$status, $headers];
}

try {
    foreach (['/assets/js/app.js?v=cache-test', '/assets/js/polling.js', '/assets/css/app.css'] as $path) {
        [$status, $headers] = cacheResponse($path);
        if ($status !== 200 || !str_contains($headers, 'cache-control: public, max-age=300, s-maxage=3600') || str_contains($headers, 'set-cookie:')) {
            throw new RuntimeException('Public asset cache policy failed: ' . $path);
        }
    }
    foreach (['/login.php', '/api/notifications.php', '/api/video/files.php', '/api/video/exports.php', '/video/export/download.php?id=1', '/api/jobs.php'] as $path) {
        [, $headers] = cacheResponse($path);
        // PHP's session cache limiter may replace private,no-store with no-store,no-cache.
        if (!str_contains($headers, 'no-store') || str_contains($headers, 'cache-control: public') || str_contains($headers, 's-maxage')) {
            throw new RuntimeException('Private response cache policy failed: ' . $path);
        }
    }
    foreach (['/assets/missing.js', '/storage/video/uploads/private.mp4', '/app/bootstrap.php', '/.env'] as $path) {
        [$status, $headers] = cacheResponse($path);
        if ($status === 200 || str_contains($headers, 'cache-control: public')) {
            throw new RuntimeException('Non-public path served or cached: ' . $path);
        }
    }
    echo "Cache headers and private paths: OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Cache headers and private paths: FAILED\n" . $error->getMessage() . "\n");
    exit(1);
}
