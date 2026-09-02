<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

function schedule_layout_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function schedule_layout_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));

    return ($hours * 60) + $minutes;
}

function schedule_layout_offset(string $time): int
{
    return max(0, schedule_layout_minutes($time) - schedule_layout_minutes('08:00'));
}

try {
    schedule_layout_assert(schedule_layout_offset('08:00') === 0, '08:00 should be the start of the schedule grid.');
    schedule_layout_assert(schedule_layout_offset('09:00') === 60, '09:00 should be +60 minutes.');
    schedule_layout_assert(schedule_layout_offset('12:30') === 270, '12:30 should be +270 minutes.');
    schedule_layout_assert(schedule_layout_offset('17:30') === 570, '17:30 should be +570 minutes.');

    $appJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');

    schedule_layout_assert(is_string($appJs), 'Could not read app.js.');
    schedule_layout_assert(str_contains($appJs, 'function scheduleOffsetMinutes'), 'Schedule offset calculation is not centralized.');
    schedule_layout_assert(str_contains($appJs, 'function layoutScheduleBlocks'), 'Schedule block layout function is missing.');
    schedule_layout_assert(substr_count($appJs, 'function layoutScheduleBlocks') === 1, 'Schedule block layout function should be defined once.');
    schedule_layout_assert(str_contains($appJs, 'requestScheduleLayout();'), 'Schedule layout is not requested after render or initialization.');

    echo "Schedule layout math: OK\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Schedule layout math: FAILED\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
