<?php
declare(strict_types=1);

use App\Support\View;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

ob_start();
View::render('components/widget', [
    'title' => 'Titulo <script>',
    'content' => 'Contenido <strong>seguro</strong>',
    'emptyState' => 'Sin <pendientes>.',
    'link' => [
        'label' => 'Ver <mas>',
        'url' => '/index.php?section=video-editor&unsafe=<tag>',
    ],
    'modifier' => 'dashboard-card--video',
    'eyebrow' => 'Editor <video>',
    'items' => [
        'Elemento <uno>',
    ],
]);
$html = ob_get_clean();

if (!is_string($html)) {
    fwrite(STDERR, "Widget rendering: FAILED\n");
    exit(1);
}

$expected = [
    'Titulo &lt;script&gt;',
    'Contenido &lt;strong&gt;seguro&lt;/strong&gt;',
    'Sin &lt;pendientes&gt;.',
    'Ver &lt;mas&gt;',
    '/index.php?section=video-editor&amp;unsafe=&lt;tag&gt;',
    'Editor &lt;video&gt;',
    'dashboard-card dashboard-card--video',
    'class="empty-state"',
    'class="widget-link"',
    'Elemento &lt;uno&gt;',
    'class="widget-list"',
];

foreach ($expected as $fragment) {
    if (!str_contains($html, $fragment)) {
        fwrite(STDERR, "Widget rendering: FAILED\n");
        exit(1);
    }
}

foreach (['<script>', '<strong>seguro</strong>', '<pendientes>', '<tag>', '<uno>'] as $unsafe) {
    if (str_contains($html, $unsafe)) {
        fwrite(STDERR, "Widget rendering: FAILED\n");
        exit(1);
    }
}

echo "Widget rendering: OK\n";
exit(0);
