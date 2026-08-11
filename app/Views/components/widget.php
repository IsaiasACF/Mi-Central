<?php
declare(strict_types=1);

use App\Support\View;

$title = $title ?? '';
$content = $content ?? '';
$emptyState = $emptyState ?? null;
$link = $link ?? null;
$items = is_array($items ?? null) ? $items : [];
$modifier = $modifier ?? '';
$eyebrow = $eyebrow ?? null;
$classes = trim('dashboard-card ' . (string) $modifier);
?>
<article class="<?= View::escape($classes) ?>">
    <div class="dashboard-card__header">
        <?php if (is_string($eyebrow) && $eyebrow !== ''): ?>
            <p class="dashboard-card__eyebrow"><?= View::escape($eyebrow) ?></p>
        <?php endif; ?>
        <h2><?= View::escape($title) ?></h2>
    </div>
    <p><?= View::escape($content) ?></p>

    <?php if ($items !== []): ?>
        <ul class="widget-list">
            <?php foreach ($items as $item): ?>
                <li><?= View::escape(is_array($item) ? ($item['label'] ?? '') : $item) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (is_string($emptyState) && $emptyState !== ''): ?>
        <?php View::render('components/empty-state', ['text' => $emptyState]); ?>
    <?php endif; ?>

    <?php if (is_array($link) && isset($link['label'], $link['url'])): ?>
        <a class="widget-link" href="<?= View::escape($link['url']) ?>"><?= View::escape($link['label']) ?></a>
    <?php endif; ?>
</article>
