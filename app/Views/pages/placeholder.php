<?php
declare(strict_types=1);

use App\Support\View;
?>
<section class="page-heading" aria-labelledby="page-title">
    <p class="eyebrow"><?= View::escape($page['phase'] ?? '') ?></p>
    <h1 id="page-title"><?= View::escape($page['title'] ?? '') ?></h1>
    <p><?= View::escape($page['description'] ?? 'Funcionalidad pendiente de implementar.') ?></p>
</section>

<section class="placeholder-panel" aria-label="Estado de la seccion">
    <h2><?= View::escape($page['label'] ?? '') ?></h2>
    <p>Esta pantalla ya forma parte de la navegacion, pero su funcionalidad interna se implementara en una fase posterior.</p>
</section>
