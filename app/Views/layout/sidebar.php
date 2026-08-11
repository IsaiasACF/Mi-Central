<?php
declare(strict_types=1);

use App\Support\Navigation;
use App\Support\View;
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
                                <?= View::escape($item['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </nav>
</aside>
<div class="sidebar-backdrop" data-sidebar-close hidden></div>
