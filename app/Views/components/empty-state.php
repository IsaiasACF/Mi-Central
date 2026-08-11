<?php
declare(strict_types=1);

use App\Support\View;

$text = $text ?? '';
?>
<div class="empty-state">
    <span aria-hidden="true"></span>
    <p><?= View::escape($text) ?></p>
</div>
