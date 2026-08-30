<?php
declare(strict_types=1);

use App\Support\View;

$activeTab = is_string($activeTab ?? null) ? $activeTab : 'for-me';
$tabs = [
    'for-me' => ['label' => 'Para mi', 'url' => '/index.php?section=discounts'],
    'favorites' => ['label' => 'Favoritos', 'url' => '/index.php?section=discounts&tab=favorites'],
    'all' => ['label' => 'Todos', 'url' => '/index.php?section=discounts&tab=all'],
];
?>
<nav class="organization-tabs discounts-tabs" aria-label="Navegacion de Descuentos">
    <?php foreach ($tabs as $tabKey => $tab): ?>
        <a
            class="organization-tab <?= $activeTab === $tabKey ? 'is-active' : '' ?>"
            href="<?= View::escape($tab['url']) ?>"
            <?= $activeTab === $tabKey ? 'aria-current="page"' : '' ?>
        >
            <?= View::escape($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>
