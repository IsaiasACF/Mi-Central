<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;

$notificationsPage = is_array($notificationsPage ?? null) ? $notificationsPage : [];
$status = (string) ($notificationsPage['status'] ?? 'all');
$items = is_array($notificationsPage['items'] ?? null) ? $notificationsPage['items'] : [];
$unreadCount = max(0, (int) ($notificationsPage['unread_count'] ?? 0));
$url = static function (string $filter): string {
    return '/index.php?section=notifications' . ($filter === 'all' ? '' : '&status=' . rawurlencode($filter));
};
?>
<section
    class="notifications-page"
    data-notification-page
    data-api-url="/api/notifications.php"
    data-csrf-token="<?= View::escape(Csrf::token()) ?>"
>
    <div class="tasks-heading">
        <div>
            <p class="eyebrow">Centro de actividad</p>
            <h1>Notificaciones</h1>
            <p class="muted">Recordatorios, tareas, proyectos, video y futuras fuentes en un solo lugar.</p>
        </div>
        <button class="button button--secondary" type="button" data-notification-action="read-all">Marcar todas como leidas</button>
    </div>

    <nav class="quick-filters" aria-label="Filtros de notificaciones">
        <a class="quick-filter<?= $status === 'all' ? ' is-active' : '' ?>" href="<?= View::escape($url('all')) ?>">Todas</a>
        <a class="quick-filter<?= $status === 'unread' ? ' is-active' : '' ?>" href="<?= View::escape($url('unread')) ?>">No leidas<?= $unreadCount > 0 ? ' (' . View::escape((string) $unreadCount) . ')' : '' ?></a>
        <a class="quick-filter<?= $status === 'read' ? ' is-active' : '' ?>" href="<?= View::escape($url('read')) ?>">Leidas</a>
    </nav>

    <div class="notifications-panel">
        <div class="tasks-list-header">
            <h2>Listado</h2>
            <span class="task-count" data-notification-page-count><?= View::escape((string) count($items)) ?></span>
        </div>

        <div class="notification-list" data-notification-list>
            <?php foreach ($items as $notification): ?>
                <?php
                $isRead = (bool) ($notification['is_read'] ?? false);
                $targetUrl = is_string($notification['target_url'] ?? null) ? (string) $notification['target_url'] : null;
                $targetLabel = is_string($notification['target_label'] ?? null) ? (string) $notification['target_label'] : null;
                ?>
                <article class="notification-item<?= $isRead ? ' is-read' : ' is-unread' ?>" data-notification-id="<?= View::escape((string) ($notification['id'] ?? '')) ?>" data-source-module="<?= View::escape((string) ($notification['source_module'] ?? '')) ?>">
                    <div class="notification-item__main">
                        <p class="dashboard-card__eyebrow"><?= View::escape($notification['type_label'] ?? 'Notificacion') ?></p>
                        <h3><?= View::escape($notification['title'] ?? '') ?></h3>
                        <?php if (is_string($notification['message'] ?? null) && $notification['message'] !== ''): ?>
                            <p><?= View::escape($notification['message']) ?></p>
                        <?php endif; ?>
                        <div class="notification-meta">
                            <span><?= View::escape(substr((string) ($notification['scheduled_at_local'] ?? ''), 0, 16)) ?></span>
                            <span><?= $isRead ? 'Leida' : 'No leida' ?></span>
                            <?php if ($targetLabel !== null): ?>
                                <span><?= View::escape($targetLabel) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="notification-item__actions">
                        <?php if ($targetUrl !== null): ?>
                            <a class="button button--secondary" href="<?= View::escape($targetUrl) ?>">Abrir</a>
                        <?php endif; ?>
                        <?php if (!$isRead): ?>
                            <button class="button button--secondary" type="button" data-notification-action="read">Marcar leida</button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <?php View::render('components/empty-state', ['text' => 'No hay notificaciones para este filtro.']); ?>
            <?php endif; ?>
        </div>
    </div>
</section>
