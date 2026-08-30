<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;
use Modules\Discounts\DiscountBenefitType;
use Modules\Discounts\DiscountPromotionFormat;

$promotions = is_array($promotions ?? null) ? $promotions : [];
$merchants = is_array($merchants ?? null) ? $merchants : [];
$benefitPrograms = is_array($benefitPrograms ?? null) ? $benefitPrograms : [];
$benefitTypeLabels = is_array($benefitTypeLabels ?? null) ? $benefitTypeLabels : DiscountBenefitType::labels();
$promotionLabels = is_array($promotionLabels ?? null) ? $promotionLabels : [
    'discount_types' => DiscountPromotionFormat::discountTypeLabels(),
    'channels' => DiscountPromotionFormat::channelLabels(),
    'weekdays' => DiscountPromotionFormat::WEEKDAYS,
];
$filters = is_array($filters ?? null) ? $filters : [];
$statusFilter = is_string($filters['status'] ?? null) ? (string) $filters['status'] : 'active';
$merchantFilter = is_string($filters['merchant_id'] ?? null) ? (string) $filters['merchant_id'] : '';
$benefitFilter = is_string($filters['benefit_program_id'] ?? null) ? (string) $filters['benefit_program_id'] : '';
$weekdayFilter = is_string($filters['weekday'] ?? null) ? (string) $filters['weekday'] : '';
$searchFilter = is_string($filters['search'] ?? null) ? (string) $filters['search'] : '';
$channelLabels = is_array($promotionLabels['channels'] ?? null) ? $promotionLabels['channels'] : DiscountPromotionFormat::channelLabels();
$discountTypeLabels = is_array($promotionLabels['discount_types'] ?? null) ? $promotionLabels['discount_types'] : DiscountPromotionFormat::discountTypeLabels();
$weekdayLabels = is_array($promotionLabels['weekdays'] ?? null) ? $promotionLabels['weekdays'] : DiscountPromotionFormat::WEEKDAYS;
?>
<section
    class="discounts-page"
    aria-labelledby="page-title"
    data-discounts-promotions-page
    data-api-url="/api/discounts/promotions.php"
    data-csrf-token="<?= View::escape(Csrf::token()) ?>"
>
    <section class="page-heading discounts-heading">
        <div>
            <p class="eyebrow">Fase 7</p>
            <h1 id="page-title">Promociones</h1>
            <p>Administra promociones manuales. Mas adelante Mi Central tambien incorporara promociones automaticamente.</p>
        </div>
        <button class="button button--primary" type="button" data-discount-promotion-open="create">Nueva promocion</button>
    </section>

    <?php View::render('components/discounts-tabs', ['activeTab' => 'promotions']); ?>

    <section class="tasks-panel discounts-promotions-panel" aria-label="Promociones manuales">
        <form class="task-filters task-filters--discount-promotions" method="get" action="/index.php">
            <input type="hidden" name="section" value="discounts">
            <input type="hidden" name="tab" value="promotions">
            <label>
                Estado
                <select name="status">
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Activas</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactivas</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todas</option>
                </select>
            </label>
            <label>
                Comercio
                <select name="merchant_id">
                    <option value="">Todos</option>
                    <?php foreach ($merchants as $merchant): ?>
                        <option value="<?= View::escape((string) ($merchant['id'] ?? '')) ?>" <?= $merchantFilter === (string) ($merchant['id'] ?? '') ? 'selected' : '' ?>>
                            <?= View::escape($merchant['name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Beneficio
                <select name="benefit_program_id">
                    <option value="">Todos</option>
                    <?php foreach ($benefitPrograms as $program): ?>
                        <?php
                        $programProduct = is_string($program['product_name'] ?? null) ? trim((string) $program['product_name']) : '';
                        $programLabel = (string) ($program['provider_name'] ?? '') . ' - ' . (string) ($program['name'] ?? '');

                        if ($programProduct !== '') {
                            $programLabel .= ' - ' . $programProduct;
                        }
                        ?>
                        <option value="<?= View::escape((string) ($program['id'] ?? '')) ?>" <?= $benefitFilter === (string) ($program['id'] ?? '') ? 'selected' : '' ?>>
                            <?= View::escape($programLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Dia
                <select name="weekday">
                    <option value="">Todos</option>
                    <?php foreach ($weekdayLabels as $weekday => $label): ?>
                        <option value="<?= View::escape((string) $weekday) ?>" <?= $weekdayFilter === (string) $weekday ? 'selected' : '' ?>>
                            <?= View::escape($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Buscar
                <input name="search" type="search" value="<?= View::escape($searchFilter) ?>" placeholder="Titulo o comercio">
            </label>
            <button class="button button--secondary" type="submit">Filtrar</button>
        </form>

        <div class="tasks-list-header discounts-list-header">
            <div>
                <h2>Promociones manuales</h2>
                <p class="muted"><?= View::escape((string) count($promotions)) ?> <?= count($promotions) === 1 ? 'promocion' : 'promociones' ?></p>
            </div>
        </div>

        <div class="discount-promotion-list" data-discount-promotion-list>
            <?php foreach ($promotions as $promotion): ?>
                <?php
                $benefits = is_array($promotion['benefits'] ?? null) ? $promotion['benefits'] : [];
                $weekdays = is_array($promotion['weekdays'] ?? null) ? $promotion['weekdays'] : [];
                $discountLabel = is_string($promotion['discount_label'] ?? null) ? (string) $promotion['discount_label'] : '';
                $state = is_string($promotion['temporal_state'] ?? null) ? (string) $promotion['temporal_state'] : '';
                $validity = is_string($promotion['validity_label'] ?? null) ? (string) $promotion['validity_label'] : '';
                $merchantName = is_string($promotion['merchant_name'] ?? null) && trim((string) $promotion['merchant_name']) !== ''
                    ? (string) $promotion['merchant_name']
                    : 'Sin comercio';
                ?>
                <article class="discount-promotion-card" data-discount-promotion-card data-promotion-id="<?= View::escape((string) ($promotion['id'] ?? '')) ?>">
                    <div class="discount-promotion-card__main">
                        <p class="discount-benefit-card__provider"><?= View::escape($merchantName) ?></p>
                        <h3><?= View::escape($promotion['title'] ?? '') ?></h3>
                        <div class="discount-promotion-card__chips">
                            <?php if ($discountLabel !== ''): ?>
                                <span><?= View::escape($discountLabel) ?></span>
                            <?php endif; ?>
                            <span><?= View::escape($channelLabels[(string) ($promotion['channel'] ?? 'both')] ?? 'Ambos') ?></span>
                            <span><?= View::escape($state) ?></span>
                            <?php if ($validity !== ''): ?>
                                <span><?= View::escape($validity) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="discount-promotion-card__meta">
                            <p>
                                <strong>Dias:</strong>
                                <?= $weekdays === []
                                    ? 'Todos los dias'
                                    : View::escape(implode(', ', array_map(static fn (int $weekday): string => (string) ($weekdayLabels[$weekday] ?? $weekday), array_map('intval', $weekdays)))) ?>
                            </p>
                            <p>
                                <strong>Beneficios:</strong>
                                <?php if ($benefits === []): ?>
                                    Disponible para todos
                                <?php else: ?>
                                    <?= View::escape(implode(', ', array_map(static function (array $benefit): string {
                                        return trim((string) ($benefit['provider_name'] ?? '') . ' - ' . (string) ($benefit['name'] ?? ''));
                                    }, $benefits))) ?>
                                <?php endif; ?>
                            </p>
                            <?php if (is_string($promotion['promo_code'] ?? null) && trim((string) $promotion['promo_code']) !== ''): ?>
                                <p><strong>Codigo:</strong> <?= View::escape($promotion['promo_code']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="discount-benefit-card__actions">
                        <button class="button button--secondary" type="button" data-discount-promotion-open="edit">Editar</button>
                        <?php if ((int) ($promotion['is_active'] ?? 0) === 1): ?>
                            <button class="button button--secondary" type="button" data-discount-promotion-action="deactivate">Desactivar</button>
                        <?php else: ?>
                            <button class="button button--secondary" type="button" data-discount-promotion-action="activate">Reactivar</button>
                        <?php endif; ?>
                        <button class="button button--danger" type="button" data-discount-promotion-action="delete">Eliminar</button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="discount-empty-state" <?= $promotions === [] ? '' : 'hidden' ?>>
            <h2>Aun no hay promociones.</h2>
            <p>Puedes agregar algunas manualmente para probar Mi Central. Mas adelante tambien se incorporaran promociones automaticamente.</p>
            <button class="button button--primary" type="button" data-discount-promotion-open="create">Nueva promocion</button>
        </div>
    </section>

    <section
        class="discount-benefit-modal discount-promotion-modal"
        data-discount-promotion-modal
        role="dialog"
        aria-modal="true"
        aria-labelledby="discount-promotion-modal-title"
        hidden
    >
        <div class="discount-benefit-modal__panel discount-promotion-modal__panel">
            <div class="discount-benefit-modal__header">
                <div>
                    <p class="eyebrow">Descuentos</p>
                    <h2 id="discount-promotion-modal-title" data-discount-promotion-modal-title>Nueva promocion</h2>
                </div>
                <button class="button button--secondary" type="button" data-discount-promotion-close aria-label="Cerrar">X</button>
            </div>

            <p class="task-message" data-discount-promotion-message hidden></p>

            <form class="task-form discount-promotion-form" data-discount-promotion-form>
                <input type="hidden" name="id" value="">
                <input type="hidden" name="merchant_mode" value="existing">

                <div class="discount-benefit-modes">
                    <button class="quick-filter is-active" type="button" data-discount-merchant-mode="existing">Elegir comercio</button>
                    <button class="quick-filter" type="button" data-discount-merchant-mode="create">Crear comercio</button>
                </div>

                <div data-discount-merchant-existing>
                    <label>
                        Comercio
                        <select name="merchant_id">
                            <option value="">Sin comercio</option>
                            <?php foreach ($merchants as $merchant): ?>
                                <option value="<?= View::escape((string) ($merchant['id'] ?? '')) ?>"><?= View::escape($merchant['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="task-form__grid task-form__grid--discount-benefit" data-discount-merchant-create hidden>
                    <label>
                        Nombre comercio *
                        <input name="merchant_name" type="text" maxlength="180" placeholder="Dunkin">
                    </label>
                    <label>
                        Categoria opcional
                        <input name="merchant_category" type="text" maxlength="120" placeholder="Comida">
                    </label>
                    <label class="task-form__wide">
                        Web opcional
                        <input name="merchant_website_url" type="url" maxlength="500" placeholder="https://...">
                    </label>
                </div>

                <div class="task-form__grid task-form__grid--discount-promotion-main">
                    <label>
                        Titulo *
                        <input name="title" type="text" maxlength="220" required placeholder="30% de descuento en tienda">
                    </label>
                    <label>
                        Tipo de descuento *
                        <select name="discount_type" data-discount-type-select>
                            <?php foreach ($discountTypeLabels as $type => $label): ?>
                                <option value="<?= View::escape((string) $type) ?>"><?= View::escape((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label data-discount-value-field>
                        Valor
                        <input name="discount_value" type="number" min="0" step="0.01" placeholder="30">
                    </label>
                    <label>
                        Tope maximo
                        <input name="max_discount_clp" type="number" min="0" step="1" placeholder="10000">
                    </label>
                    <label>
                        Codigo promocional
                        <input name="promo_code" type="text" maxlength="120">
                    </label>
                    <label>
                        Canal *
                        <select name="channel">
                            <?php foreach ($channelLabels as $channel => $label): ?>
                                <option value="<?= View::escape((string) $channel) ?>"><?= View::escape((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Desde
                        <input name="starts_on" type="date">
                    </label>
                    <label>
                        Hasta
                        <input name="ends_on" type="date">
                    </label>
                    <label class="toggle-field">
                        <input name="is_active" type="checkbox" value="1" checked>
                        Activa
                    </label>
                </div>

                <label>
                    Descripcion
                    <textarea name="description" rows="3" maxlength="5000"></textarea>
                </label>

                <fieldset class="label-picker">
                    <legend>Dias aplicables</legend>
                    <div class="label-picker__options">
                        <?php foreach ($weekdayLabels as $weekday => $label): ?>
                            <label class="label-picker__option">
                                <input type="checkbox" name="weekdays[]" value="<?= View::escape((string) $weekday) ?>">
                                <?= View::escape($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="muted">Si no eliges dias, aplica todos los dias.</p>
                </fieldset>

                <fieldset class="label-picker">
                    <legend>Beneficios requeridos</legend>
                    <?php if ($benefitPrograms === []): ?>
                        <p class="muted">No hay beneficios en el catalogo. La promocion quedara disponible para todos.</p>
                    <?php else: ?>
                        <div class="label-picker__options">
                            <?php foreach ($benefitPrograms as $program): ?>
                                <?php
                                $programProduct = is_string($program['product_name'] ?? null) ? trim((string) $program['product_name']) : '';
                                $programLabel = (string) ($program['provider_name'] ?? '') . ' - ' . (string) ($program['name'] ?? '');

                                if ($programProduct !== '') {
                                    $programLabel .= ' - ' . $programProduct;
                                }
                                ?>
                                <label class="label-picker__option">
                                    <input type="checkbox" name="benefit_program_ids[]" value="<?= View::escape((string) ($program['id'] ?? '')) ?>">
                                    <?= View::escape($programLabel) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <p class="muted">Sin beneficios asociados significa promocion general. Si eliges varios, la semantica es OR.</p>
                </fieldset>

                <label>
                    Terminos
                    <textarea name="terms" rows="3" maxlength="5000" placeholder="Maximo 2 compras por cliente. No acumulable."></textarea>
                </label>

                <label>
                    URL de origen
                    <input name="source_url" type="url" maxlength="500" placeholder="https://...">
                </label>

                <div class="task-form__actions">
                    <button class="button button--secondary" type="button" data-discount-promotion-close>Cancelar</button>
                    <button class="button button--primary" type="submit">Guardar</button>
                </div>
            </form>
        </div>
    </section>
</section>
