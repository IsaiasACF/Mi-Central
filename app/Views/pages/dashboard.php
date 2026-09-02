<?php
declare(strict_types=1);

use App\Support\View;

$summary = is_array($summary ?? null) ? $summary : ['meta' => [], 'sections' => []];
$meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
$sections = is_array($summary['sections'] ?? null) ? $summary['sections'] : [];
?>
<section class="dashboard-hero" aria-labelledby="page-title">
    <div>
        <p class="eyebrow"><?= View::escape($meta['context'] ?? 'Resumen de hoy') ?></p>
        <h1 id="page-title">Hola, <?= View::escape($username ?? '') ?></h1>
        <p class="dashboard-hero__text">Mi Central esta listo para reunir tus proximas herramientas personales.</p>
    </div>
    <time class="dashboard-date" datetime="<?= View::escape($meta['date_iso'] ?? '') ?>">
        <?= View::escape($meta['date_label'] ?? '') ?>
    </time>
</section>

<section class="dashboard-grid dashboard-grid--home" aria-label="Dashboard de inicio">
    <?php foreach ($sections as $section): ?>
        <?php
        if (($section['key'] ?? '') === 'inbox') {
            View::render('components/inbox-quick-capture', [
                'section' => $section,
            ]);
            continue;
        }

        $items = is_array($section['items'] ?? null) ? $section['items'] : [];
        $count = (int) ($section['count'] ?? 0);

        View::render('components/widget', [
            'modifier' => $section['modifier'] ?? '',
            'eyebrow' => $section['eyebrow'] ?? '',
            'title' => $section['title'] ?? '',
            'content' => $section['description'] ?? '',
            'items' => $items,
            'emptyState' => $count === 0 ? ($section['empty_state'] ?? '') : null,
            'link' => $section['link'] ?? null,
        ]);
        ?>
    <?php endforeach; ?>
</section>
