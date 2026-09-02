<?php
declare(strict_types=1);

namespace Modules\Video;

use PDO;

final class VideoDashboardSummaryService
{
    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{items: array<int, array{label: string}>, empty_state: string, link: array{label: string, url: string}, videos_count: int, exports_count: int}
     */
    public function summary(int $userId): array
    {
        $videosCount = $this->count('video_files', $userId);
        $exportsCount = $this->count('video_export_jobs', $userId);
        $active = $this->activeItem($userId);
        $latest = $active === null ? $this->latestExportItem($userId) : null;
        $items = [];

        if ($active !== null) {
            $items[] = ['label' => $active];
        } elseif ($latest !== null) {
            $items[] = ['label' => $latest];
        } elseif ($videosCount > 0) {
            $items[] = ['label' => $videosCount === 1 ? '1 video disponible' : $videosCount . ' videos disponibles'];
        }

        return [
            'items' => $items,
            'empty_state' => 'Aun no hay actividad de video.',
            'link' => [
                'label' => $exportsCount > 0 ? 'Ver mas' : 'Abrir editor',
                'url' => $exportsCount > 0 ? '/index.php?section=video&tab=processings' : '/index.php?section=video',
            ],
            'videos_count' => $videosCount,
            'exports_count' => $exportsCount,
        ];
    }

    private function count(string $table, int $userId): int
    {
        if (!in_array($table, ['video_files', 'video_export_jobs'], true)) {
            return 0;
        }

        if (!$this->tableExists($table)) {
            return 0;
        }

        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = :user_id");
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function activeItem(int $userId): ?string
    {
        $exportProcessing = $this->activeExport($userId, 'processing');

        if ($exportProcessing !== null) {
            return 'Exportando ' . $this->name($exportProcessing) . $this->progressSuffix($exportProcessing);
        }

        $exportPending = $this->activeExport($userId, 'pending');

        if ($exportPending !== null) {
            return 'Exportacion en cola: ' . $this->name($exportPending);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeExport(int $userId, string $status): ?array
    {
        if (!$this->tableExists('video_export_jobs') || !$this->tableExists('video_files')) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT vej.output_name AS name, vej.progress_percent
             FROM video_export_jobs vej
             INNER JOIN video_files vf ON vf.id = vej.video_id AND vf.user_id = vej.user_id
             WHERE vej.user_id = :user_id AND vej.status = :status
             ORDER BY vej.updated_at DESC, vej.id DESC
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'status' => $status,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function latestExportItem(int $userId): ?string
    {
        if (!$this->tableExists('video_export_jobs') || !$this->tableExists('video_files')) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT vej.output_name AS name, vej.status
             FROM video_export_jobs vej
             INNER JOIN video_files vf ON vf.id = vej.video_id AND vf.user_id = vej.user_id
             WHERE vej.user_id = :user_id
             ORDER BY vej.created_at DESC, vej.id DESC
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return 'Ultimo procesado: ' . $this->name($row) . ' · ' . $this->statusLabel((string) ($row['status'] ?? 'pending'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function name(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));

        return $name !== '' ? $name : 'Video';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function progressSuffix(array $row, string $fallback = ''): string
    {
        $progress = $row['progress_percent'] ?? null;

        if ($progress === null || $progress === '') {
            return $fallback === '' ? '' : ' · ' . $fallback;
        }

        return ' · ' . (int) round((float) $progress) . '%';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'En cola',
            'processing' => 'Procesando',
            'completed' => 'Completado',
            'failed' => 'Fallido',
            default => 'En cola',
        };
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $statement->execute(['table' => $table]);

        $exists = (int) $statement->fetchColumn() === 1;
        $this->tableExistsCache[$table] = $exists;

        return $exists;
    }
}
