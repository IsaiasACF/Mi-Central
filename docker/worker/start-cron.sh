#!/bin/sh
set -eu

ENV_FILE=/tmp/mi-central-worker-env.sh

mkdir -p \
    /var/www/html/storage/logs \
    /var/www/html/storage/video/uploads \
    /var/www/html/storage/video/jobs \
    /var/www/html/storage/video/exports \
    /var/www/html/storage/video/temp
touch /var/www/html/storage/logs/reminders-worker.log
touch /var/www/html/storage/logs/video-metadata-worker.log
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
    "FFMPEG_BIN",
    "FFPROBE_BIN",
    "TZ",
];

foreach ($keys as $key) {
    $value = getenv($key);

    if ($value !== false) {
        echo "export " . $key . "=" . escapeshellarg($value) . PHP_EOL;
    }
}
' > "$ENV_FILE"
chmod 600 "$ENV_FILE"
crontab /var/www/html/docker/worker/crontab

echo "Mi Central worker cron started."
exec cron -f
