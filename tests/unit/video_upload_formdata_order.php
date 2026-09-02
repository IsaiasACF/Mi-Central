<?php
declare(strict_types=1);

$script = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/app.js');

if (!is_string($script)) {
    fwrite(STDERR, "Video upload FormData order: FAILED\n");
    exit(1);
}

$submitPosition = strpos($script, "form.addEventListener('submit'");
$formDataPosition = strpos($script, 'var formData = new FormData(form);', $submitPosition === false ? 0 : $submitPosition);
$pendingPosition = strpos($script, 'setUploadPending(true);', $submitPosition === false ? 0 : $submitPosition);

if ($submitPosition === false || $formDataPosition === false || $pendingPosition === false || $formDataPosition > $pendingPosition) {
    fwrite(STDERR, "Video upload FormData order: FAILED\n");
    fwrite(STDERR, "The upload FormData must be created before disabling the file input.\n");
    exit(1);
}

echo "Video upload FormData order: OK\n";
