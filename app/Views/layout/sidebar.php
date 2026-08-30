<?php
declare(strict_types=1);

use App\Support\Navigation;
use App\Support\View;

$navIcon = static function (mixed $icon): string {
    $icon = is_string($icon) ? $icon : 'circle';
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/>',
        'check-square' => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'percent' => '<path d="M19 5 5 19"/><circle cx="7" cy="7" r="2"/><circle cx="17" cy="17" r="2"/>',
        'wallet' => '<path d="M19 7V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-1"/><path d="M3 8h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-4a2 2 0 0 1 0-4h6"/><path d="M16 12h.01"/>',
        'play' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3V9Z"/>',
        'settings' => '<path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5Z"/><path d="M19.4 15a1.8 1.8 0 0 0 .36 1.98l.04.04a2 2 0 1 1-2.83 2.83l-.04-.04a1.8 1.8 0 0 0-1.98-.36 1.8 1.8 0 0 0-1.1 1.65V21a2 2 0 1 1-4 0v-.06A1.8 1.8 0 0 0 8.75 19.3a1.8 1.8 0 0 0-1.98.36l-.04.04a2 2 0 1 1-2.83-2.83l.04-.04A1.8 1.8 0 0 0 4.3 15a1.8 1.8 0 0 0-1.65-1.1H2.6a2 2 0 1 1 0-4h.06A1.8 1.8 0 0 0 4.3 8.75a1.8 1.8 0 0 0-.36-1.98l-.04-.04A2 2 0 1 1 6.73 3.9l.04.04a1.8 1.8 0 0 0 1.98.36h.01A1.8 1.8 0 0 0 9.86 2.6V2.5a2 2 0 1 1 4 0v.06a1.8 1.8 0 0 0 1.1 1.65 1.8 1.8 0 0 0 1.98-.36l.04-.04a2 2 0 1 1 2.83 2.83l-.04.04a1.8 1.8 0 0 0-.36 1.98v.01a1.8 1.8 0 0 0 1.65 1.1h.1a2 2 0 1 1 0 4h-.06A1.8 1.8 0 0 0 19.4 15Z"/>',
        'bell' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'circle' => '<circle cx="12" cy="12" r="7"/>',
    ];
    $path = $paths[$icon] ?? $paths['circle'];

    return '<svg class="nav-link__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
};
?>
<aside class="app-sidebar" id="app-sidebar">
    <nav class="sidebar-nav" aria-label="Navegacion principal">
        <?php foreach ($navigationGroups as $groupIndex => $group): ?>
            <section class="nav-group">
                <?php
                $label = $group['label'] ?? null;
                $items = is_array($group['items'] ?? null) ? $group['items'] : [];
                $isCollapsible = $label !== null;
                $isOpen = false;

                foreach ($items as $item) {
                    if (($item['key'] ?? null) === $activeSection) {
                        $isOpen = true;
                        break;
                    }
                }
                ?>
                <?php if ($isCollapsible): ?>
                    <?php $panelId = 'nav-group-' . (int) $groupIndex; ?>
                    <h2 class="nav-group__heading">
                        <button
                            class="nav-group__button<?= $isOpen ? ' is-open' : '' ?>"
                            type="button"
                            aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                            aria-controls="<?= View::escape($panelId) ?>"
                            data-nav-toggle
                        >
                            <span><?= View::escape($label) ?></span>
                            <span class="nav-chevron" aria-hidden="true"></span>
                        </button>
                    </h2>
                <?php endif; ?>
                <ul
                    class="nav-list<?= $isCollapsible ? ' nav-list--nested' : '' ?>"
                    <?= $isCollapsible ? 'id="' . View::escape($panelId) . '" data-nav-panel' . ($isOpen ? '' : ' hidden') : '' ?>
                >
                    <?php foreach ($items as $item): ?>
                        <?php $isActive = $item['key'] === $activeSection; ?>
                        <li>
                            <a
                                class="nav-link<?= $isActive ? ' is-active' : '' ?>"
                                href="<?= View::escape(Navigation::url($item['key'])) ?>"
                                <?= $isActive ? 'aria-current="page"' : '' ?>
                            >
                                <?= $navIcon($item['icon'] ?? null) ?>
                                <span><?= View::escape($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </nav>
</aside>
<div class="sidebar-backdrop" data-sidebar-close hidden></div>
