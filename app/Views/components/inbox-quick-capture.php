<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;

$section = is_array($section ?? null) ? $section : [];
$items = is_array($section['items'] ?? null) ? $section['items'] : [];
$count = (int) ($section['count'] ?? 0);
$classes = trim('dashboard-card ' . (string) ($section['modifier'] ?? ''));
?>
<article
    class="<?= View::escape($classes) ?>"
    data-inbox-quick
    data-api-url="/api/organization/tasks.php"
    data-csrf-token="<?= View::escape(Csrf::token()) ?>"
>
    <div class="dashboard-card__header">
        <p class="dashboard-card__eyebrow"><?= View::escape($section['eyebrow'] ?? '') ?></p>
        <h2><?= View::escape($section['title'] ?? 'Bandeja rapida') ?></h2>
    </div>
    <p><?= View::escape($section['description'] ?? '') ?></p>

    <form class="inbox-quick-form" data-inbox-quick-form>
        <label class="sr-only" for="inbox-quick-title">Que necesitas recordar?</label>
        <input
            id="inbox-quick-title"
            name="title"
            type="text"
            maxlength="180"
            placeholder="Que necesitas recordar?"
            autocomplete="off"
            required
        >
        <button class="button button--primary" type="submit">Anadir</button>
    </form>
    <p class="task-message" role="status" aria-live="polite" data-inbox-quick-message hidden></p>

    <div class="inbox-quick-summary">
        <span data-inbox-quick-count><?= $count ?></span>
        <span><?= $count === 1 ? 'pendiente por organizar' : 'pendientes por organizar' ?></span>
    </div>

    <?php if ($items !== []): ?>
        <ul class="widget-list" data-inbox-quick-list>
            <?php foreach ($items as $item): ?>
                <li><?= View::escape(is_array($item) ? ($item['label'] ?? '') : $item) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <div data-inbox-quick-empty>
            <?php View::render('components/empty-state', ['text' => $section['empty_state'] ?? 'Bandeja vacia. No tienes nada pendiente de organizar.']); ?>
        </div>
        <ul class="widget-list" data-inbox-quick-list hidden></ul>
    <?php endif; ?>

    <?php if (is_array($section['link'] ?? null) && isset($section['link']['label'], $section['link']['url'])): ?>
        <a class="widget-link" href="<?= View::escape($section['link']['url']) ?>"><?= View::escape($section['link']['label']) ?></a>
    <?php endif; ?>
</article>
