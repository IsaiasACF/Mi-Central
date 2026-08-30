#!/bin/sh
set -eu

ENV_FILE=/tmp/mi-central-worker-env.sh

mkdir -p \
    /var/www/html/storage/cache \
    /var/www/html/storage/logs \
    /var/www/html/storage/temp \
    /var/www/html/storage/video/uploads \
    /var/www/html/storage/video/jobs \
    /var/www/html/storage/video/exports \
    /var/www/html/storage/video/temp
touch /var/www/html/storage/logs/reminders-worker.log
touch /var/www/html/storage/logs/video-metadata-worker.log
touch /var/www/html/storage/logs/video-exports-worker.log
touch /var/www/html/storage/logs/video-cleanup-worker.log
touch /var/www/html/storage/logs/discount-collectors-worker.log
chown -R www-data:www-data /var/www/html/storage
/usr/local/bin/php -r '
$keys = [
    "APP_NAME",
    "APP_ENV",
    "APP_DEBUG",
    "APP_URL",
    "APP_TIMEZONE",
    "DB_HOST",
    "DB_PORT",
    "DB_DATABASE",
    "DB_USERNAME",
    "DB_PASSWORD",
    "DB_CHARSET",
    "SESSION_NAME",
    "SESSION_SECURE",
    "SESSION_IDLE_TIMEOUT",
    "COLLECTOR_HTTP_TIMEOUT",
    "COLLECTOR_HTTP_CONNECT_TIMEOUT",
    "COLLECTOR_HTTP_MAX_REDIRECTS",
    "COLLECTOR_USER_AGENT",
    "COLLECTOR_MAX_RESPONSE_BYTES",
    "COLLECTOR_DEFAULT_INTERVAL_MINUTES",
    "COLLECTOR_MIN_INTERVAL_MINUTES",
    "COLLECTOR_RETRY_MINUTES",
    "COLLECTOR_STALE_AFTER_MINUTES",
    "COLLECTOR_BANCO_CHILE_MAX_ITEMS",
    "COLLECTOR_BANCO_CHILE_REQUEST_DELAY_MS",
    "COLLECTOR_SANTANDER_MAX_ITEMS",
    "COLLECTOR_SANTANDER_REQUEST_DELAY_MS",
    "COLLECTOR_BANCOESTADO_MAX_ITEMS",
    "COLLECTOR_BANCOESTADO_REQUEST_DELAY_MS",
    "FFMPEG_BIN",
    "FFPROBE_BIN",
    "VIDEO_MAX_UPLOAD_MB",
    "VIDEO_EXPORT_RETENTION_DAYS",
    "TZ",
];

foreach ($keys as $key) {
    $value = getenv($key);

    if ($value !== false) {
        echo "export " . $key . "=" . escapeshellarg($value) . PHP_EOL;
    }
}
' > "$ENV_FILE"
chown root:www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"
crontab /var/www/html/docker/worker/crontab

echo "Mi Central worker cron started."
exec cron -f
