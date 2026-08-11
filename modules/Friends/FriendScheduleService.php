<?php
declare(strict_types=1);

namespace Modules\Friends;

final class FriendScheduleService
{
    private const NAME_MAX_LENGTH = 180;
    private const SHORT_TEXT_MAX_LENGTH = 180;
    private const COURSE_CODE_MAX_LENGTH = 80;
    private const ROOM_MAX_LENGTH = 120;
    private const EXCEPTION_TYPES = ['cancelled', 'absent', 'modified'];
    private const IMPORT_MAX_BLOCKS = 100;
    private const IMPORT_WEEKDAYS = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    public function __construct(private readonly FriendScheduleRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createFriend(int $userId, array $input): array
    {
        $data = [
            'name' => $this->requiredText($input['name'] ?? '', 'name', self::NAME_MAX_LENGTH),
            'university' => $this->optionalText($input['university'] ?? null, self::SHORT_TEXT_MAX_LENGTH),
            'default_campus' => $this->optionalText($input['default_campus'] ?? null, self::SHORT_TEXT_MAX_LENGTH),
            'notes' => $this->optionalText($input['notes'] ?? null, null),
            'is_active' => $this->boolFlag($input['is_active'] ?? true),
        ];

        $friendId = $this->repository->createFriend($userId, $data);

        return $this->getFriend($userId, $friendId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFriend(int $userId, int $friendId): ?array
    {
        return $this->repository->findFriendForUser($userId, $this->positiveId($friendId, 'friend_id'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createFriendScheduleEntry(int $userId, int $friendId, array $input): array
    {
        $friendId = $this->positiveId($friendId, 'friend_id');

        if (!$this->repository->friendBelongsToUser($userId, $friendId)) {
            throw new FriendValidationException('Amigo invalido.');
        }

        $data = $this->scheduleEntryData($input);
        $entryId = $this->repository->createFriendScheduleEntry($friendId, $data);
        $entry = $this->repository->findFriendScheduleEntryForUser($userId, $entryId);

        return $entry === null ? [] : $this->annotateEntry($entry, 'friend', $userId, $friendId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFriendScheduleEntries(int $userId, int $friendId): array
    {
        $friendId = $this->positiveId($friendId, 'friend_id');

        return $this->annotateEntries($this->repository->listFriendScheduleEntries($userId, $friendId), 'friend', $userId, $friendId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createFriendScheduleException(int $userId, int $friendId, array $input): array
    {
        $friendId = $this->positiveId($friendId, 'friend_id');

        if (!$this->repository->friendBelongsToUser($userId, $friendId)) {
            throw new FriendValidationException('Amigo invalido.');
        }

        $scheduleEntryId = $this->optionalPositiveId($input['schedule_entry_id'] ?? null, 'schedule_entry_id');

        if ($scheduleEntryId !== null) {
            $entry = $this->repository->findFriendScheduleEntryForUser($userId, $scheduleEntryId);

            if ($entry === null || (int) $entry['friend_id'] !== $friendId) {
                throw new FriendValidationException('Entrada de horario invalida.');
            }
        }

        $startsAt = $this->optionalTime($input['starts_at'] ?? null, 'starts_at');
        $endsAt = $this->optionalTime($input['ends_at'] ?? null, 'ends_at');

        if ($startsAt !== null && $endsAt !== null) {
            $this->assertTimeRange($startsAt, $endsAt);
        }

        $data = [
            'schedule_entry_id' => $scheduleEntryId,
            'exception_date' => $this->date($input['exception_date'] ?? null, 'exception_date'),
            'type' => $this->exceptionType($input['type'] ?? ''),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'course_name' => $this->optionalText($input['course_name'] ?? null, self::NAME_MAX_LENGTH),
            'course_code' => $this->optionalText($input['course_code'] ?? null, self::COURSE_CODE_MAX_LENGTH),
            'room' => $this->optionalText($input['room'] ?? null, self::ROOM_MAX_LENGTH),
            'campus' => $this->optionalText($input['campus'] ?? null, self::SHORT_TEXT_MAX_LENGTH),
            'notes' => $this->optionalText($input['notes'] ?? null, null),
        ];

        $exceptionId = $this->repository->createFriendScheduleException($friendId, $data);

        foreach ($this->repository->listFriendScheduleExceptions($userId, $friendId) as $exception) {
            if ((int) $exception['id'] === $exceptionId) {
                return $this->annotateException($exception, 'friend');
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFriendScheduleExceptions(int $userId, int $friendId): array
    {
        return $this->annotateExceptions(
            $this->repository->listFriendScheduleExceptions($userId, $this->positiveId($friendId, 'friend_id')),
            'friend'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listExceptionsForDate(int $userId, string $targetType, ?int $friendId, string $date): array
    {
        $targetType = $this->targetType($targetType);
        $date = $this->normalizeDate($date, 'date');

        if ($targetType === 'user') {
            return $this->annotateExceptions($this->repository->listUserScheduleExceptionsForDate($userId, $date), 'user');
        }

        $friendId = $this->requiredFriendId($friendId);

        if (!$this->repository->friendBelongsToUser($userId, $friendId)) {
            throw new FriendValidationException('Amigo invalido.');
        }

        return $this->annotateExceptions($this->repository->listFriendScheduleExceptionsForDate($userId, $friendId, $date), 'friend');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createUserScheduleEntry(int $userId, array $input): array
    {
        $entryId = $this->repository->createUserScheduleEntry($userId, $this->scheduleEntryData($input));

        foreach ($this->repository->listUserScheduleEntries($userId) as $entry) {
            if ((int) $entry['id'] === $entryId) {
                return $this->annotateEntry($entry, 'user', $userId, null);
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUserScheduleEntries(int $userId): array
    {
        return $this->annotateEntries($this->repository->listUserScheduleEntries($userId), 'user', $userId, null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUserScheduleExceptions(int $userId): array
    {
        return $this->annotateExceptions($this->repository->listUserScheduleExceptions($userId), 'user');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listEntries(int $userId, string $targetType, ?int $friendId = null, array $filters = []): array
    {
        $targetType = $this->targetType($targetType);
        $date = $this->activeDate($filters);
        $queryFilters = $date === null ? [] : ['date' => $date];

        if ($targetType === 'user') {
            return $this->annotateEntries(
                $this->repository->listUserScheduleEntriesFiltered($userId, $queryFilters),
                'user',
                $userId,
                null
            );
        }

        $friendId = $this->requiredFriendId($friendId);

        if (!$this->repository->friendBelongsToUser($userId, $friendId)) {
            throw new FriendValidationException('Amigo invalido.');
        }

        return $this->annotateEntries(
            $this->repository->listFriendScheduleEntriesFiltered($userId, $friendId, $queryFilters),
            'friend',
            $userId,
            $friendId
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createEntry(int $userId, string $targetType, ?int $friendId, array $input): array
    {
        $targetType = $this->targetType($targetType);

        if ($targetType === 'user') {
            return $this->createUserScheduleEntry($userId, $input);
        }

        return $this->createFriendScheduleEntry($userId, $this->requiredFriendId($friendId), $input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function updateEntry(int $userId, string $targetType, int $entryId, array $input, ?int $friendId = null): ?array
    {
        $targetType = $this->targetType($targetType);
        $entryId = $this->positiveId($entryId, 'id');
        $data = $this->scheduleEntryData($input);

        if ($targetType === 'user') {
            if ($this->repository->findUserScheduleEntryForUser($userId, $entryId) === null) {
                return null;
            }

            $this->repository->updateUserScheduleEntry($userId, $entryId, $data);
            $entry = $this->repository->findUserScheduleEntryForUser($userId, $entryId);

            return $entry === null ? null : $this->annotateEntry($entry, 'user', $userId, null);
        }

        $entry = $this->repository->findFriendScheduleEntryForUser($userId, $entryId);

        if ($entry === null) {
            return null;
        }

        if ($friendId !== null && (int) $entry['friend_id'] !== $this->requiredFriendId($friendId)) {
            throw new FriendValidationException('Entrada de horario invalida.');
        }

        $this->repository->updateFriendScheduleEntry($userId, $entryId, $data);
        $updated = $this->repository->findFriendScheduleEntryForUser($userId, $entryId);

        return $updated === null ? null : $this->annotateEntry($updated, 'friend', $userId, (int) $updated['friend_id']);
    }

    public function deleteEntry(int $userId, string $targetType, int $entryId): bool
    {
        $targetType = $this->targetType($targetType);
        $entryId = $this->positiveId($entryId, 'id');

        if ($targetType === 'user') {
            return $this->repository->deleteUserScheduleEntry($userId, $entryId);
        }

        return $this->repository->deleteFriendScheduleEntry($userId, $entryId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createException(int $userId, string $targetType, ?int $friendId, array $input): array
    {
        $targetType = $this->targetType($targetType);

        if ($targetType === 'user') {
            $data = $this->exceptionData($userId, 'user', null, $input);
            $exceptionId = $this->repository->createUserScheduleException($userId, $data);
            $exception = $this->repository->findUserScheduleExceptionForUser($userId, $exceptionId);

            return $exception === null ? [] : $this->annotateException($exception, 'user');
        }

        return $this->createFriendScheduleException($userId, $this->requiredFriendId($friendId), $input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function updateException(int $userId, string $targetType, int $exceptionId, array $input, ?int $friendId = null): ?array
    {
        $targetType = $this->targetType($targetType);
        $exceptionId = $this->positiveId($exceptionId, 'id');

        if ($targetType === 'user') {
            if ($this->repository->findUserScheduleExceptionForUser($userId, $exceptionId) === null) {
                return null;
            }

            $data = $this->exceptionData($userId, 'user', null, $input);
            $this->repository->updateUserScheduleException($userId, $exceptionId, $data);
            $exception = $this->repository->findUserScheduleExceptionForUser($userId, $exceptionId);

            return $exception === null ? null : $this->annotateException($exception, 'user');
        }

        $exception = $this->repository->findFriendScheduleExceptionForUser($userId, $exceptionId);

        if ($exception === null) {
            return null;
        }

        $friendId = $friendId === null ? (int) $exception['friend_id'] : $this->requiredFriendId($friendId);

        if ((int) $exception['friend_id'] !== $friendId) {
            throw new FriendValidationException('Excepcion invalida.');
        }

        $data = $this->exceptionData($userId, 'friend', $friendId, $input);
        $this->repository->updateFriendScheduleException($userId, $exceptionId, $data);
        $updated = $this->repository->findFriendScheduleExceptionForUser($userId, $exceptionId);

        return $updated === null ? null : $this->annotateException($updated, 'friend');
    }

    public function deleteException(int $userId, string $targetType, int $exceptionId): bool
    {
        $targetType = $this->targetType($targetType);
        $exceptionId = $this->positiveId($exceptionId, 'id');

        if ($targetType === 'user') {
            return $this->repository->deleteUserScheduleException($userId, $exceptionId);
        }

        return $this->repository->deleteFriendScheduleException($userId, $exceptionId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listExceptions(int $userId, string $targetType, ?int $friendId = null): array
    {
        $targetType = $this->targetType($targetType);

        if ($targetType === 'user') {
            return $this->listUserScheduleExceptions($userId);
        }

        return $this->listFriendScheduleExceptions($userId, $this->requiredFriendId($friendId));
    }

    /**
     * @return array{target: array<string, mixed>, total: int, blocks: array<int, array<string, mixed>>, errors: array<int, string>, warnings: array<int, string>}
     */
    public function previewImport(int $userId, string $targetType, ?int $friendId, string $json): array
    {
        $targetType = $this->targetType($targetType);
        $friend = null;

        if ($targetType === 'friend') {
            $friendId = $this->requiredFriendId($friendId);
            $friend = $this->repository->findFriendForUser($userId, $friendId);

            if ($friend === null) {
                throw new FriendValidationException('Amigo invalido.');
            }
        }

        $result = $this->parseImportJson($json);

        if ($result['errors'] !== []) {
            return [
                'target' => $this->importTarget($targetType, $friend),
                'total' => 0,
                'blocks' => [],
                'errors' => $result['errors'],
                'warnings' => [],
            ];
        }

        $blocks = [];
        $warnings = [];

        foreach ($result['blocks'] as $index => $data) {
            $duplicate = $this->duplicateExists($userId, $targetType, $friendId, $data);
            $entry = array_merge($data, [
                'import_index' => $index + 1,
                'weekday_name' => array_search((int) $data['weekday'], self::IMPORT_WEEKDAYS, true),
                'starts_at_input' => $this->timeInput($data['starts_at']),
                'ends_at_input' => $this->timeInput($data['ends_at']),
                'effective_campus' => $targetType === 'friend' && ($data['campus'] ?? null) === null
                    ? ($friend['default_campus'] ?? null)
                    : ($data['campus'] ?? null),
                'is_duplicate' => $duplicate,
                'warnings' => [],
            ]);

            if ($duplicate) {
                $entry['warnings'][] = 'Bloque aparentemente duplicado; se omitira al importar.';
                $warnings[] = 'Bloque ' . ($index + 1) . ': duplicado detectado.';
            }

            $blocks[] = $entry;
        }

        return [
            'target' => $this->importTarget($targetType, $friend),
            'total' => count($blocks),
            'blocks' => $blocks,
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{target: array<string, mixed>, imported: int, skipped_duplicates: int, errors: array<int, string>, warnings: array<int, string>}
     */
    public function importJson(int $userId, string $targetType, ?int $friendId, string $json): array
    {
        $preview = $this->previewImport($userId, $targetType, $friendId, $json);

        if ($preview['errors'] !== []) {
            return [
                'target' => $preview['target'],
                'imported' => 0,
                'skipped_duplicates' => 0,
                'errors' => $preview['errors'],
                'warnings' => $preview['warnings'],
            ];
        }

        $targetType = $this->targetType($targetType);
        $friendId = $targetType === 'friend' ? $this->requiredFriendId($friendId) : null;
        $imported = 0;
        $skipped = 0;

        $this->repository->beginTransaction();

        try {
            foreach ($preview['blocks'] as $block) {
                if (!empty($block['is_duplicate'])) {
                    $skipped++;
                    continue;
                }

                $input = [
                    'weekday' => (int) $block['weekday'],
                    'starts_at' => (string) $block['starts_at'],
                    'ends_at' => (string) $block['ends_at'],
                    'course_name' => (string) $block['course_name'],
                    'course_code' => $block['course_code'] ?? '',
                    'room' => $block['room'] ?? '',
                    'campus' => $block['campus'] ?? '',
                    'valid_from' => $block['valid_from'] ?? '',
                    'valid_until' => $block['valid_until'] ?? '',
                ];

                $this->createEntry($userId, $targetType, $friendId, $input);
                $imported++;
            }

            $this->repository->commit();
        } catch (\Throwable $exception) {
            $this->repository->rollBack();
            throw $exception;
        }

        return [
            'target' => $preview['target'],
            'imported' => $imported,
            'skipped_duplicates' => $skipped,
            'errors' => [],
            'warnings' => $preview['warnings'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function scheduleEntryData(array $input): array
    {
        $startsAt = $this->time($input['starts_at'] ?? null, 'starts_at');
        $endsAt = $this->time($input['ends_at'] ?? null, 'ends_at');
        $this->assertTimeRange($startsAt, $endsAt);

        $validFrom = $this->optionalDate($input['valid_from'] ?? null, 'valid_from');
        $validUntil = $this->optionalDate($input['valid_until'] ?? null, 'valid_until');

        if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) {
            throw new FriendValidationException('Periodo de vigencia invalido.');
        }

        return [
            'weekday' => $this->weekday($input['weekday'] ?? null),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'course_name' => $this->requiredText($input['course_name'] ?? '', 'course_name', self::NAME_MAX_LENGTH),
            'course_code' => $this->optionalText($input['course_code'] ?? null, self::COURSE_CODE_MAX_LENGTH),
            'room' => $this->optionalText($input['room'] ?? null, self::ROOM_MAX_LENGTH),
            'campus' => $this->optionalText($input['campus'] ?? null, self::SHORT_TEXT_MAX_LENGTH),
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function exceptionData(int $userId, string $targetType, ?int $friendId, array $input): array
    {
        $scheduleEntryId = $this->optionalPositiveId($input['schedule_entry_id'] ?? null, 'schedule_entry_id');

        if ($scheduleEntryId !== null) {
            if ($targetType === 'user') {
                if ($this->repository->findUserScheduleEntryForUser($userId, $scheduleEntryId) === null) {
                    throw new FriendValidationException('Entrada de horario invalida.');
                }
            } else {
                $entry = $this->repository->findFriendScheduleEntryForUser($userId, $scheduleEntryId);

                if ($entry === null || (int) $entry['friend_id'] !== $this->requiredFriendId($friendId)) {
                    throw new FriendValidationException('Entrada de horario invalida.');
                }
            }
        }

        $startsAt = $this->optionalTime($input['starts_at'] ?? null, 'starts_at');
        $endsAt = $this->optionalTime($input['ends_at'] ?? null, 'ends_at');

        if ($startsAt !== null && $endsAt !== null) {
            $this->assertTimeRange($startsAt, $endsAt);
        }

        return [
            'schedule_entry_id' => $scheduleEntryId,
            'exception_date' => $this->date($input['exception_date'] ?? null, 'exception_date'),
            'type' => $this->exceptionType($input['type'] ?? ''),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'course_name' => $this->optionalText($input['course_name'] ?? null, self::NAME_MAX_LENGTH),
            'course_code' => $this->optionalText($input['course_code'] ?? null, self::COURSE_CODE_MAX_LENGTH),
            'room' => $this->optionalText($input['room'] ?? null, self::ROOM_MAX_LENGTH),
            'campus' => $this->optionalText($input['campus'] ?? null, self::SHORT_TEXT_MAX_LENGTH),
            'notes' => $this->optionalText($input['notes'] ?? null, null),
        ];
    }

    /**
     * @return array{blocks: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    private function parseImportJson(string $json): array
    {
        if (strlen($json) > 256000) {
            return ['blocks' => [], 'errors' => ['El archivo JSON supera el tamano permitido.']];
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['blocks' => [], 'errors' => ['JSON invalido.']];
        }

        $errors = [];
        $blocks = [];

        if (!is_array($decoded)) {
            return ['blocks' => [], 'errors' => ['El JSON debe ser un objeto.']];
        }

        if (($decoded['version'] ?? null) !== 1) {
            $errors[] = 'Version no soportada.';
        }

        if (!isset($decoded['schedule']) || !is_array($decoded['schedule'])) {
            $errors[] = 'schedule debe ser un array.';
        } elseif (count($decoded['schedule']) > self::IMPORT_MAX_BLOCKS) {
            $errors[] = 'La importacion no puede superar ' . self::IMPORT_MAX_BLOCKS . ' bloques.';
        }

        if ($errors !== []) {
            return ['blocks' => [], 'errors' => $errors];
        }

        foreach ($decoded['schedule'] as $index => $row) {
            $label = 'Bloque ' . ($index + 1) . ': ';

            if (!is_array($row)) {
                $errors[] = $label . 'debe ser un objeto.';
                continue;
            }

            if (array_key_exists('user_id', $row) || array_key_exists('friend_id', $row)) {
                $errors[] = $label . 'no puede incluir user_id ni friend_id.';
                continue;
            }

            $weekdayName = is_string($row['weekday'] ?? null) ? strtolower((string) $row['weekday']) : '';

            if (!array_key_exists($weekdayName, self::IMPORT_WEEKDAYS)) {
                $errors[] = $label . 'weekday invalido.';
                continue;
            }

            try {
                $blocks[] = $this->scheduleEntryData([
                    'weekday' => self::IMPORT_WEEKDAYS[$weekdayName],
                    'starts_at' => $row['starts_at'] ?? null,
                    'ends_at' => $row['ends_at'] ?? null,
                    'course_name' => $row['course_name'] ?? '',
                    'course_code' => $row['course_code'] ?? null,
                    'room' => $row['room'] ?? null,
                    'campus' => $row['campus'] ?? null,
                    'valid_from' => $row['valid_from'] ?? null,
                    'valid_until' => $row['valid_until'] ?? null,
                ]);
            } catch (FriendValidationException $exception) {
                $errors[] = $label . $exception->getMessage();
            }
        }

        return $errors === []
            ? ['blocks' => $blocks, 'errors' => []]
            : ['blocks' => [], 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function duplicateExists(int $userId, string $targetType, ?int $friendId, array $data): bool
    {
        if ($targetType === 'user') {
            return $this->repository->userScheduleDuplicateExists($userId, $data);
        }

        return $this->repository->friendScheduleDuplicateExists($userId, $this->requiredFriendId($friendId), $data);
    }

    /**
     * @param array<string, mixed>|null $friend
     * @return array<string, mixed>
     */
    private function importTarget(string $targetType, ?array $friend): array
    {
        if ($targetType === 'user') {
            return [
                'type' => 'user',
                'label' => 'Mi horario',
                'friend_id' => null,
            ];
        }

        return [
            'type' => 'friend',
            'label' => (string) ($friend['name'] ?? 'Amigo'),
            'friend_id' => $friend['id'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function annotateEntries(array $entries, string $targetType, int $userId, ?int $friendId): array
    {
        $annotated = [];

        foreach ($entries as $entry) {
            $annotated[] = $this->annotateEntry($entry, $targetType, $userId, $friendId);
        }

        return $annotated;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function annotateEntry(array $entry, string $targetType, int $userId, ?int $friendId): array
    {
        $entry['target_type'] = $targetType;
        $campus = is_string($entry['campus'] ?? null) && trim((string) $entry['campus']) !== ''
            ? (string) $entry['campus']
            : null;
        $defaultCampus = is_string($entry['default_campus'] ?? null) && trim((string) $entry['default_campus']) !== ''
            ? (string) $entry['default_campus']
            : null;
        $entry['effective_campus'] = $entry['effective_campus'] ?? $campus ?? $defaultCampus;
        $entry['starts_at_input'] = $this->timeInput($entry['starts_at'] ?? null);
        $entry['ends_at_input'] = $this->timeInput($entry['ends_at'] ?? null);
        $entry['warnings'] = $this->overlapWarnings($entry, $targetType, $userId, $friendId);
        $entry['has_overlap'] = $entry['warnings'] !== [];

        return $entry;
    }

    /**
     * @param array<int, array<string, mixed>> $exceptions
     * @return array<int, array<string, mixed>>
     */
    private function annotateExceptions(array $exceptions, string $targetType): array
    {
        $annotated = [];

        foreach ($exceptions as $exception) {
            $annotated[] = $this->annotateException($exception, $targetType);
        }

        return $annotated;
    }

    /**
     * @param array<string, mixed> $exception
     * @return array<string, mixed>
     */
    private function annotateException(array $exception, string $targetType): array
    {
        $exception['target_type'] = $targetType;
        $exception['starts_at_input'] = $this->timeInput($exception['starts_at'] ?? null);
        $exception['ends_at_input'] = $this->timeInput($exception['ends_at'] ?? null);
        $exception['type_label'] = match ((string) ($exception['type'] ?? '')) {
            'cancelled' => 'Cancelada',
            'absent' => 'No asiste',
            'modified' => 'Modificada',
            default => 'Excepcion',
        };

        return $exception;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<int, string>
     */
    private function overlapWarnings(array $entry, string $targetType, int $userId, ?int $friendId): array
    {
        $weekday = (int) ($entry['weekday'] ?? 0);
        $startsAt = (string) ($entry['starts_at'] ?? '');
        $endsAt = (string) ($entry['ends_at'] ?? '');
        $entryId = (int) ($entry['id'] ?? 0);
        $entries = $targetType === 'user'
            ? $this->repository->listUserScheduleEntries($userId)
            : $this->repository->listFriendScheduleEntries($userId, $this->requiredFriendId($friendId));

        foreach ($entries as $other) {
            if ((int) $other['id'] === $entryId || (int) $other['weekday'] !== $weekday) {
                continue;
            }

            if (!$this->periodsOverlap($entry, $other)) {
                continue;
            }

            if ($startsAt < (string) $other['ends_at'] && $endsAt > (string) $other['starts_at']) {
                return ['Este bloque se superpone con otra clase del horario.'];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    private function periodsOverlap(array $first, array $second): bool
    {
        $firstFrom = $first['valid_from'] ?? null;
        $firstUntil = $first['valid_until'] ?? null;
        $secondFrom = $second['valid_from'] ?? null;
        $secondUntil = $second['valid_until'] ?? null;

        if (is_string($firstUntil) && is_string($secondFrom) && $firstUntil < $secondFrom) {
            return false;
        }

        if (is_string($secondUntil) && is_string($firstFrom) && $secondUntil < $firstFrom) {
            return false;
        }

        return true;
    }

    private function timeInput(mixed $value): string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 5) : '';
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function activeDate(array $filters): ?string
    {
        $show = is_string($filters['show'] ?? null) ? (string) $filters['show'] : 'active';

        if ($show === 'all') {
            return null;
        }

        if (isset($filters['date']) && $filters['date'] !== '') {
            return $this->normalizeDate($filters['date'], 'date');
        }

        return (new \DateTimeImmutable('now'))->format('Y-m-d');
    }

    private function targetType(string $targetType): string
    {
        if (!in_array($targetType, ['user', 'friend'], true)) {
            throw new FriendValidationException('Tipo de horario invalido.');
        }

        return $targetType;
    }

    private function requiredFriendId(?int $friendId): int
    {
        if ($friendId === null) {
            throw new FriendValidationException('Amigo requerido.');
        }

        return $this->positiveId($friendId, 'friend_id');
    }

    private function weekday(mixed $value): int
    {
        if (is_int($value)) {
            $weekday = $value;
        } elseif (is_string($value) && preg_match('/\A[1-7]\z/', $value) === 1) {
            $weekday = (int) $value;
        } else {
            throw new FriendValidationException('Dia de semana invalido.');
        }

        if ($weekday < 1 || $weekday > 7) {
            throw new FriendValidationException('Dia de semana invalido.');
        }

        return $weekday;
    }

    private function time(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw new FriendValidationException("Hora requerida: {$field}.");
        }

        return $this->normalizeTime($value, $field);
    }

    private function optionalTime(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeTime($value, $field);
    }

    private function normalizeTime(mixed $value, string $field): string
    {
        $value = trim((string) $value);

        if (preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?\z/', $value, $matches) !== 1) {
            throw new FriendValidationException("Hora invalida: {$field}.");
        }

        return $matches[1] . ':' . $matches[2] . ':' . ($matches[3] ?? '00');
    }

    private function assertTimeRange(string $startsAt, string $endsAt): void
    {
        if ($endsAt <= $startsAt) {
            throw new FriendValidationException('La hora de termino debe ser posterior al inicio.');
        }
    }

    private function date(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw new FriendValidationException("Fecha requerida: {$field}.");
        }

        return $this->normalizeDate($value, $field);
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeDate($value, $field);
    }

    private function normalizeDate(mixed $value, string $field): string
    {
        $value = trim((string) $value);

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw new FriendValidationException("Fecha invalida: {$field}.");
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (!checkdate($month, $day, $year)) {
            throw new FriendValidationException("Fecha invalida: {$field}.");
        }

        return $value;
    }

    private function exceptionType(mixed $type): string
    {
        $type = (string) $type;

        if (!in_array($type, self::EXCEPTION_TYPES, true)) {
            throw new FriendValidationException('Tipo de excepcion invalido.');
        }

        return $type;
    }

    private function requiredText(mixed $value, string $field, int $maxLength): string
    {
        $value = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($value === '' || $length > $maxLength) {
            throw new FriendValidationException("Texto invalido: {$field}.");
        }

        return $value;
    }

    private function optionalText(mixed $value, ?int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($maxLength !== null && $length > $maxLength) {
            throw new FriendValidationException('Texto demasiado largo.');
        }

        return $value;
    }

    private function boolFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        if (is_string($value) && in_array($value, ['0', '1'], true)) {
            return (int) $value;
        }

        throw new FriendValidationException('Bandera invalida.');
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            $id = (int) $value;
        } else {
            throw new FriendValidationException("Identificador invalido: {$field}.");
        }

        if ($id < 1) {
            throw new FriendValidationException("Identificador invalido: {$field}.");
        }

        return $id;
    }

    private function optionalPositiveId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, $field);
    }
}
