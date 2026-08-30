<?php
declare(strict_types=1);

namespace Modules\Organization;

final class NoteService
{
    private const TITLE_MAX_LENGTH = 180;
    private const STATUSES = ['active', 'completed'];

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ?LabelService $labels = null,
    )
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
            'status' => 'active',
            'completed_at' => null,
        ];
        $labelIds = $this->labels?->labelIdsFromInput($input) ?? [];
        $this->labels?->assertLabelIdsBelongToUser($userId, $labelIds);

        $noteId = $this->notes->create($userId, $data);
        $this->labels?->syncEntityLabels($userId, 'note', $noteId, $labelIds);

        return $this->get($userId, $noteId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, int $noteId): ?array
    {
        $note = $this->notes->findByIdForUser($userId, $this->positiveId($noteId, 'id'));

        if ($note === null) {
            return null;
        }

        if ($this->labels !== null) {
            $note = $this->labels->attachLabelsToEntities($userId, 'note', [$note])[0] ?? $note;
        }

        return $note;
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

        if (isset($filters['label_id']) && $filters['label_id'] !== '' && $filters['label_id'] !== 'all') {
            $labelId = $this->filterId($filters['label_id'], 'label_id');

            if ($this->labels === null || $this->labels->get($userId, $labelId) === null) {
                throw new TaskValidationException('Relacion invalida.');
            }

            $normalized['label_id'] = $labelId;
        }

        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'all') {
            $normalized['status'] = $this->status($filters['status']);
        }

        $notes = $this->notes->listForUser($userId, $normalized);

        return $this->labels === null ? $notes : $this->labels->attachLabelsToEntities($userId, 'note', $notes);
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

        if (array_key_exists('status', $input)) {
            $data['status'] = $this->status($input['status']);
            $data['completed_at'] = $data['status'] === 'completed' ? \App\Support\DateTimeHelper::nowUtcStorage() : null;
        }

        $labelIds = array_key_exists('label_ids', $input) && $this->labels !== null
            ? $this->labels->labelIdsFromInput($input)
            : null;

        if ($labelIds !== null) {
            $this->labels?->assertLabelIdsBelongToUser($userId, $labelIds);
        }

        $this->notes->update($userId, $noteId, $data);

        if ($labelIds !== null) {
            $this->labels?->syncEntityLabels($userId, 'note', $noteId, $labelIds);
        }

        return $this->get($userId, $noteId);
    }

    public function delete(int $userId, int $noteId): bool
    {
        return $this->notes->delete($userId, $this->positiveId($noteId, 'id'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function complete(int $userId, int $noteId): ?array
    {
        return $this->update($userId, $noteId, ['status' => 'completed']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reopen(int $userId, int $noteId): ?array
    {
        return $this->update($userId, $noteId, ['status' => 'active']);
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

    private function status(mixed $status): string
    {
        $status = (string) $status;

        if (!in_array($status, self::STATUSES, true)) {
            throw new TaskValidationException('Estado invalido.');
        }

        return $status;
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

    private function filterId(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $this->positiveId($value, $field);
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return $this->positiveId((int) $value, $field);
        }

        throw new TaskValidationException("Identificador invalido: {$field}.");
    }
}
