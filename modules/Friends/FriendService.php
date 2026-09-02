<?php
declare(strict_types=1);

namespace Modules\Friends;

final class FriendService
{
    private const NAME_MAX_LENGTH = 180;
    private const SHORT_TEXT_MAX_LENGTH = 180;
    private const NOTES_MAX_LENGTH = 5000;

    public function __construct(private readonly FriendRepository $friends)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $data = $this->friendData($input, true);
        $friendId = $this->friends->create($userId, $data);

        return $this->get($userId, $friendId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $friendId): ?array
    {
        return $this->friends->findByIdForUser($userId, $this->positiveId($friendId, 'id'));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, array $filters = []): array
    {
        $status = is_string($filters['status'] ?? null) ? (string) $filters['status'] : 'active';

        if (!in_array($status, ['active', 'all', 'inactive'], true)) {
            $status = 'active';
        }

        return $this->friends->listForUser($userId, ['status' => $status]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $friendId, array $input): ?array
    {
        $friendId = $this->positiveId($friendId, 'id');

        if ($this->friends->findByIdForUser($userId, $friendId) === null) {
            return null;
        }

        $this->friends->update($userId, $friendId, $this->friendData($input, false));

        return $this->get($userId, $friendId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setActive(int $userId, int $friendId, bool $active): ?array
    {
        $friendId = $this->positiveId($friendId, 'id');

        if ($this->friends->findByIdForUser($userId, $friendId) === null) {
            return null;
        }

        $this->friends->setActive($userId, $friendId, $active);

        return $this->get($userId, $friendId);
    }

    public function delete(int $userId, int $friendId): bool
    {
        return $this->friends->delete($userId, $this->positiveId($friendId, 'id'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function friendData(array $input, bool $creating): array
    {
        $data = [];

        if ($creating || array_key_exists('name', $input)) {
            $data['name'] = $this->requiredText($input['name'] ?? '', 'nombre', self::NAME_MAX_LENGTH);
        }

        if ($creating || array_key_exists('university', $input)) {
            $data['university'] = $this->optionalText($input['university'] ?? null, self::SHORT_TEXT_MAX_LENGTH);
        }

        if ($creating || array_key_exists('default_campus', $input)) {
            $data['default_campus'] = $this->optionalText($input['default_campus'] ?? null, self::SHORT_TEXT_MAX_LENGTH);
        }

        if ($creating || array_key_exists('notes', $input)) {
            $data['notes'] = $this->optionalText($input['notes'] ?? null, self::NOTES_MAX_LENGTH);
        }

        if ($creating || array_key_exists('is_active', $input)) {
            $data['is_active'] = $this->boolFlag($input['is_active'] ?? true);
        }

        return $data;
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

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($length > $maxLength) {
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

        throw new FriendValidationException('Estado invalido.');
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new FriendValidationException("Identificador invalido: {$field}.");
        }

        return $value;
    }
}
