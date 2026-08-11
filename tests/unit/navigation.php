<?php
declare(strict_types=1);

use App\Support\Navigation;

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$home = Navigation::resolve(null);

if (!is_array($home) || $home['key'] !== 'home') {
    fwrite(STDERR, "Navigation: FAILED\n");
    exit(1);
}

$known = Navigation::resolve('video-editor');

if (!is_array($known) || $known['title'] !== 'Video') {
    fwrite(STDERR, "Navigation: FAILED\n");
    exit(1);
}

$organization = Navigation::resolve('organization');

if (!is_array($organization) || $organization['label'] !== 'Organizacion') {
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
