<?php
declare(strict_types=1);

namespace Modules\Organization;

final class NoteService
{
    private const TITLE_MAX_LENGTH = 180;

    public function __construct(private readonly NoteRepository $notes)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input): array
    {
        $data = [
            'space_id' => $this->spaceId($userId, $input['space_id'] ?? null),
            'title' => $this->title($input['title'] ?? ''),
            'content' => $this->content($input['content'] ?? ''),
        ];

        $noteId = $this->notes->create($userId, $data);

        return $this->get($userId, $noteId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $noteId): ?array
    {
        return $this->notes->findByIdForUser($userId, $this->positiveId($noteId, 'id'));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(int $userId, array $filters = []): array
    {
        $normalized = [];

        if (isset($filters['space_id']) && $filters['space_id'] !== '' && $filters['space_id'] !== null) {
            if ($filters['space_id'] === 'none') {
                $normalized['space_id_is_null'] = true;
            } else {
                $normalized['space_id'] = $this->spaceId($userId, $filters['space_id']);
            }
        }

        return $this->notes->listForUser($userId, $normalized);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function update(int $userId, int $noteId, array $input): ?array
    {
        $noteId = $this->positiveId($noteId, 'id');

        if ($this->notes->findByIdForUser($userId, $noteId) === null) {
            return null;
        }

        $data = [];

        if (array_key_exists('space_id', $input)) {
            $data['space_id'] = $this->spaceId($userId, $input['space_id']);
        }

        if (array_key_exists('title', $input)) {
            $data['title'] = $this->title($input['title']);
        }

        if (array_key_exists('content', $input)) {
            $data['content'] = $this->content($input['content']);
        }

        $this->notes->update($userId, $noteId, $data);

        return $this->get($userId, $noteId);
    }

    public function delete(int $userId, int $noteId): bool
    {
        return $this->notes->delete($userId, $this->positiveId($noteId, 'id'));
    }

    private function title(mixed $title): string
    {
        $title = trim((string) $title);
        $length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);

        if ($title === '' || $length > self::TITLE_MAX_LENGTH) {
            throw new TaskValidationException('Titulo invalido.');
        }

        return $title;
    }

    private function content(mixed $content): string
    {
        return trim((string) $content);
    }

    private function spaceId(int $userId, mixed $spaceId): ?int
    {
        if ($spaceId === null || $spaceId === '') {
            return null;
        }

        if (!is_int($spaceId) && !(is_string($spaceId) && preg_match('/\A[1-9][0-9]*\z/', $spaceId) === 1)) {
            throw new TaskValidationException('Espacio invalido.');
        }

        $spaceId = (int) $spaceId;

        if (!$this->notes->spaceBelongsToUser($userId, $spaceId)) {
            throw new TaskValidationException('Relacion invalida.');
        }

        return $spaceId;
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new TaskValidationException("Identificador invalido: {$field}.");
        }

        return $value;
    }
}
