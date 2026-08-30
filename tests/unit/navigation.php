<?php
declare(strict_types=1);

use App\Support\Navigation;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$home = Navigation::resolve(null);

if (!is_array($home) || $home['key'] !== 'home') {
    fwrite(STDERR, "Navigation: FAILED\n");
    exit(1);
}

$known = Navigation::resolve('video');

if (!is_array($known) || $known['title'] !== 'Video' || $known['label'] !== 'Video') {
    fwrite(STDERR, "Navigation: FAILED\n");
    exit(1);
}

$settings = Navigation::resolve('settings');

if (
    Navigation::url('video') !== '/index.php?section=video'
    || Navigation::resolve('video-editor') !== null
    || Navigation::resolve('video-processing') !== null
    || !is_array($settings)
    || $settings['label'] !== 'Configuracion'
    || Navigation::resolve('video-settings') !== null
) {
    fwrite(STDERR, "Navigation: FAILED\n");
    exit(1);
}

$organization = Navigation::resolve('organization');

if (!is_array($organization) || $organization['label'] !== 'Organizacion') {
    fwrite(STDERR, "Navigation: FAILED\n");
    exit(1);
}

$discounts = Navigation::resolve('discounts');

if (
    !is_array($discounts)
    || $discounts['label'] !== 'Descuentos'
    || Navigation::url('discounts') !== '/index.php?section=discounts'
    || Navigation::resolve('discounts-benefits') !== null
    || Navigation::resolve('discounts-for-me') !== null
) {
    fwrite(STDERR, "Navigation: FAILED\n");
    exit(1);
}

foreach ([
    '../config/database.php',
    'public/login.php',
    'organization-inbox',
    'organization-tasks',
    'organization-university',
    '',
    null,
] as $section) {
    $resolved = Navigation::resolve($section);

    if ($section === '' || $section === null) {
        if (!is_array($resolved) || $resolved['key'] !== 'home') {
            fwrite(STDERR, "Navigation: FAILED\n");
            exit(1);
        }

        continue;
    }

    if ($resolved !== null) {
        fwrite(STDERR, "Navigation: FAILED\n");
        exit(1);
    }
}

foreach (Navigation::items() as $item) {
    $url = Navigation::url($item['key']);

    if (!str_starts_with($url, '/index.php')) {
        fwrite(STDERR, "Navigation: FAILED\n");
        exit(1);
    }
}

echo "Navigation: OK\n";
exit(0);
