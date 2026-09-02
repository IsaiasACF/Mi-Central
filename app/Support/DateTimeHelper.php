<?php
declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Organization\TaskValidationException;

final class DateTimeHelper
{
    public const DEFAULT_TIMEZONE = 'America/Santiago';
    public const UTC = 'UTC';

    public static function timezone(?string $timezone = null): DateTimeZone
    {
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : self::DEFAULT_TIMEZONE;

        return new DateTimeZone($timezone);
    }

    public static function utcTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::UTC);
    }

    public static function nowUtcStorage(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function nowLocal(?string $timezone = null): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone($timezone));
    }

    public static function localInputToUtcStorage(mixed $value, string $field, ?string $timezone = null): string
    {
        if ($value === null || $value === '') {
            throw new TaskValidationException("Fecha requerida: {$field}.");
        }

        $date = self::localInputToDateTime($value, $field, $timezone);

        return $date->setTimezone(self::utcTimezone())->format('Y-m-d H:i:s');
    }

    public static function optionalLocalInputToUtcStorage(mixed $value, string $field, ?string $timezone = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::localInputToUtcStorage($value, $field, $timezone);
    }

    public static function localDateEndToUtcStorage(mixed $value, string $field, ?string $timezone = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace('T', ' ', trim((string) $value));
        $timezoneObject = self::timezone($timezone);
        $formats = [
            '!Y-m-d H:i:s' => 'Y-m-d H:i:s',
            '!Y-m-d H:i' => 'Y-m-d H:i',
            '!Y-m-d' => 'Y-m-d',
        ];

        foreach ($formats as $parseFormat => $displayFormat) {
            $date = DateTimeImmutable::createFromFormat($parseFormat, $value, $timezoneObject);

            if ($date instanceof DateTimeImmutable && $date->format($displayFormat) === $value) {
                if ($displayFormat === 'Y-m-d') {
                    $date = $date->setTime(23, 59, 59);
                }

                return $date->setTimezone(self::utcTimezone())->format('Y-m-d H:i:s');
            }
        }

        throw new TaskValidationException("Fecha invalida: {$field}.");
    }

    public static function utcStorageToLocalDateTime(string $value, ?string $timezone = null): DateTimeImmutable
    {
        return (new DateTimeImmutable($value, self::utcTimezone()))->setTimezone(self::timezone($timezone));
    }

    public static function utcStorageToLocalStorage(?string $value, ?string $timezone = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::utcStorageToLocalDateTime($value, $timezone)->format('Y-m-d H:i:s');
    }

    public static function utcStorageToLocalInput(?string $value, ?string $timezone = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::utcStorageToLocalDateTime($value, $timezone)->format('Y-m-d\TH:i');
    }

    private static function localInputToDateTime(mixed $value, string $field, ?string $timezone = null): DateTimeImmutable
    {
        $value = str_replace('T', ' ', trim((string) $value));
        $formats = [
            '!Y-m-d H:i:s' => 'Y-m-d H:i:s',
            '!Y-m-d H:i' => 'Y-m-d H:i',
        ];
        $timezoneObject = self::timezone($timezone);

        foreach ($formats as $parseFormat => $displayFormat) {
            $date = DateTimeImmutable::createFromFormat($parseFormat, $value, $timezoneObject);

            if ($date instanceof DateTimeImmutable && $date->format($displayFormat) === $value) {
                return $date;
            }
        }

        throw new TaskValidationException("Fecha invalida: {$field}.");
    }
}
