<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;

$notifications = is_array($notifications ?? null) ? $notifications : [];
$notificationUnreadCount = max(0, (int) ($notifications['unread_count'] ?? 0));
$recentNotifications = is_array($notifications['recent'] ?? null) ? $notifications['recent'] : [];
?>
<header class="app-header">
    <div class="app-header__brand">
        <button class="sidebar-toggle" type="button" aria-label="Abrir navegacion" aria-controls="app-sidebar" aria-expanded="false">
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
            <span aria-hidden="true"></span>
        </button>
        <a class="brand-link" href="/index.php"><?= View::escape($appName ?? 'Mi Central') ?></a>
    </div>
    <div class="app-header__session">
        <div
            class="notification-center"
            data-notification-center
            data-api-url="/api/notifications.php"
            data-csrf-token="<?= View::escape(Csrf::token()) ?>"
        >
            <button
                class="notification-bell"
                type="button"
                aria-label="Abrir notificaciones"
                aria-expanded="false"
                aria-controls="notification-panel"
                data-notification-toggle
            >
                <svg class="notification-bell__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                    <path d="M10 21h4"></path>
                </svg>
                <span class="notification-bell__count" data-notification-count <?= $notificationUnreadCount > 0 ? '' : 'hidden' ?>>
                    <?= View::escape((string) $notificationUnreadCount) ?>
                </span>
            </button>
            <section class="notification-popover" id="notification-panel" data-notification-panel hidden>
                <div class="notification-popover__header">
                    <h2>Notificaciones</h2>
                    <button class="button button--secondary" type="button" data-notification-action="read-all">Marcar todas</button>
                </div>
                <div class="notification-list notification-list--compact" data-notification-list>
                    <?php foreach ($recentNotifications as $notification): ?>
                        <?php
                        $isRead = (bool) ($notification['is_read'] ?? false);
                        $targetUrl = is_string($notification['target_url'] ?? null) ? (string) $notification['target_url'] : null;
                        ?>
                        <article class="notification-item<?= $isRead ? ' is-read' : ' is-unread' ?>" data-notification-id="<?= View::escape((string) ($notification['id'] ?? '')) ?>" data-source-module="<?= View::escape((string) ($notification['source_module'] ?? '')) ?>">
                            <div class="notification-item__main">
                                <p class="dashboard-card__eyebrow"><?= View::escape($notification['type_label'] ?? 'Actividad') ?></p>
                                <h3><?= View::escape($notification['title'] ?? '') ?></h3>
                                <?php if (is_string($notification['message'] ?? null) && $notification['message'] !== ''): ?>
                                    <p><?= View::escape($notification['message']) ?></p>
                                <?php endif; ?>
                                <div class="notification-meta">
                                    <span><?= View::escape(substr((string) ($notification['scheduled_at_local'] ?? ''), 0, 16)) ?></span>
                                    <span><?= $isRead ? 'Leida' : 'No leida' ?></span>
                                </div>
                            </div>
                            <div class="notification-item__actions">
                                <?php if ($targetUrl !== null): ?>
                                    <a class="button button--secondary" href="<?= View::escape($targetUrl) ?>">Abrir</a>
                                <?php endif; ?>
                                <?php if (!$isRead): ?>
                                    <button class="button button--secondary" type="button" data-notification-action="read">Leida</button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($recentNotifications === []): ?>
                        <p class="notification-empty">Sin notificaciones recientes.</p>
                    <?php endif; ?>
                </div>
                <a class="notification-popover__all" href="/index.php?section=notifications">Ver todas las notificaciones</a>
            </section>
        </div>
        <span class="session-user">Sesion iniciada como: <?= View::escape($username ?? '') ?></span>
        <form action="/logout.php" method="post" class="logout-form">
            <?= Csrf::input() ?>
            <button type="submit" class="button button--secondary">Cerrar sesion</button>
        </form>
    </div>
</header>
