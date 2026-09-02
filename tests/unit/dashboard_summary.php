<?php
declare(strict_types=1);

use Modules\Dashboard\DashboardSummaryService;

$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';

$summary = (new DashboardSummaryService($config['app']))->summary();
$expectedSections = ['today', 'upcoming', 'friends', 'coincidences', 'discounts', 'video', 'inbox'];

if (!isset($summary['meta'], $summary['sections']) || !is_array($summary['sections'])) {
    fwrite(STDERR, "Dashboard summary: FAILED\n");
    exit(1);
}

if (($summary['meta']['timezone'] ?? null) !== 'America/Santiago') {
    fwrite(STDERR, "Dashboard summary: FAILED\n");
    exit(1);
}

foreach ($expectedSections as $sectionKey) {
    $section = $summary['sections'][$sectionKey] ?? null;

    if (!is_array($section)) {
        fwrite(STDERR, "Dashboard summary: FAILED\n");
        exit(1);
    }

    if (!isset($section['items'], $section['count'], $section['empty_state']) || !is_array($section['items'])) {
        fwrite(STDERR, "Dashboard summary: FAILED\n");
        exit(1);
    }

    if ($section['items'] !== [] || $section['count'] !== count($section['items'])) {
        fwrite(STDERR, "Dashboard summary: FAILED\n");
        exit(1);
    }
}

$emptyMessages = [
    'today' => 'Sin pendientes para mostrar.',
    'upcoming' => 'No hay proximos elementos con fecha.',
    'friends' => 'Aun no hay amigos activos con horario.',
    'coincidences' => 'Sin coincidencias proximas.',
    'discounts' => 'No hay descuentos disponibles.',
    'video' => 'Aun no hay actividad de video.',
];

foreach ($emptyMessages as $sectionKey => $message) {
    if (($summary['sections'][$sectionKey]['empty_state'] ?? null) !== $message) {
        fwrite(STDERR, "Dashboard summary: FAILED\n");
        exit(1);
    }
}

$dashboardView = file_get_contents(dirname(__DIR__, 2) . '/app/Views/pages/dashboard.php');

if (!is_string($dashboardView) || preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|PDO|Connection::)\b/i', $dashboardView) === 1) {
    fwrite(STDERR, "Dashboard summary: FAILED\n");
    exit(1);
}

echo "Dashboard summary: OK\n";
exit(0);
