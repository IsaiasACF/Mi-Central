<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;
use Modules\Discounts\DiscountBenefitType;

$userBenefits = is_array($userBenefits ?? null) ? $userBenefits : [];
$benefitPrograms = is_array($benefitPrograms ?? null) ? $benefitPrograms : [];
$benefitTypeLabels = is_array($benefitTypeLabels ?? null) ? $benefitTypeLabels : DiscountBenefitType::labels();
$activeCount = count($userBenefits);
$filterTypes = [];

foreach ($userBenefits as $benefit) {
    $type = is_string($benefit['benefit_type'] ?? null) ? (string) $benefit['benefit_type'] : 'other';

    if (isset($benefitTypeLabels[$type])) {
        $filterTypes[$type] = $benefitTypeLabels[$type];
    }
}
?>
<section
    class="discounts-page"
    aria-labelledby="page-title"
    data-discounts-benefits-page
    data-api-url="/api/discounts/user-benefits.php"
    data-csrf-token="<?= View::escape(Csrf::token()) ?>"
>
    <section class="page-heading discounts-heading">
        <div>
            <p class="eyebrow">Fase 7</p>
            <h1 id="page-title">Mis beneficios</h1>
            <p>Registra los bancos, tarjetas, operadores o membresias que tienes para encontrar descuentos compatibles mas adelante.</p>
        </div>
        <button class="button button--primary" type="button" data-discount-benefit-open="create">Agregar beneficio</button>
    </section>

    <?php View::render('components/discounts-tabs', ['activeTab' => 'benefits']); ?>

    <section class="tasks-panel discounts-benefits-panel" aria-label="Beneficios registrados">
        <div class="tasks-list-header discounts-list-header">
            <div>
                <h2>Beneficios activos</h2>
                <p class="muted"><span data-discount-benefit-count><?= View::escape((string) $activeCount) ?></span> <?= $activeCount === 1 ? 'beneficio activo' : 'beneficios activos' ?></p>
            </div>
        </div>

        <?php if ($filterTypes !== []): ?>
            <div class="quick-filters discounts-filters" aria-label="Filtrar beneficios">
                <button class="quick-filter is-active" type="button" data-benefit-type-filter="all">Todos</button>
                <?php foreach ($filterTypes as $type => $label): ?>
                    <button class="quick-filter" type="button" data-benefit-type-filter="<?= View::escape($type) ?>"><?= View::escape($label) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="discount-benefit-list" data-discount-benefit-list>
            <?php foreach ($userBenefits as $benefit): ?>
                <?php
                $type = is_string($benefit['benefit_type'] ?? null) ? (string) $benefit['benefit_type'] : 'other';
                $typeLabel = $benefitTypeLabels[$type] ?? DiscountBenefitType::label($type);
                $nickname = is_string($benefit['nickname'] ?? null) ? trim((string) $benefit['nickname']) : '';
                $notes = is_string($benefit['notes'] ?? null) ? trim((string) $benefit['notes']) : '';
                $product = is_string($benefit['product_name'] ?? null) ? trim((string) $benefit['product_name']) : '';
                $title = $nickname !== '' ? $nickname : (string) ($benefit['benefit_name'] ?? '');
                ?>
                <article
                    class="discount-benefit-card"
                    data-benefit-card
                    data-benefit-type="<?= View::escape($type) ?>"
                    data-user-benefit-id="<?= View::escape((string) ($benefit['id'] ?? '')) ?>"
                    data-nickname="<?= View::escape($nickname) ?>"
                    data-notes="<?= View::escape($notes) ?>"
                    data-benefit-name="<?= View::escape((string) ($benefit['benefit_name'] ?? '')) ?>"
                >
                    <div class="discount-benefit-card__main">
                        <p class="discount-benefit-card__provider"><?= View::escape($benefit['provider_name'] ?? '') ?></p>
                        <h3><?= View::escape($title) ?></h3>
                        <?php if ($nickname !== ''): ?>
                            <p class="discount-benefit-card__program"><?= View::escape($benefit['benefit_name'] ?? '') ?></p>
                        <?php endif; ?>
                        <div class="discount-benefit-card__meta">
                            <span><?= View::escape($typeLabel) ?></span>
                            <?php if ($product !== ''): ?>
                                <span><?= View::escape($product) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($notes !== ''): ?>
                            <p class="discount-benefit-card__notes"><?= View::escape($notes) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="discount-benefit-card__actions">
                        <button class="button button--secondary" type="button" data-discount-benefit-open="edit">Editar</button>
                        <button class="button button--danger" type="button" data-discount-benefit-remove>Quitar</button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="discount-empty-state" data-discount-benefit-empty <?= $userBenefits === [] ? '' : 'hidden' ?>>
            <h2>Todavia no has agregado beneficios.</h2>
            <p>Agrega tus tarjetas, bancos, operadores o membresias para que Mi Central pueda recomendarte promociones mas adelante.</p>
            <button class="button button--primary" type="button" data-discount-benefit-open="create">Agregar beneficio</button>
        </div>
    </section>

    <section
        class="discount-benefit-modal"
        data-discount-benefit-modal
        role="dialog"
        aria-modal="true"
        aria-labelledby="discount-benefit-modal-title"
        hidden
    >
        <div class="discount-benefit-modal__panel">
            <div class="discount-benefit-modal__header">
                <div>
                    <p class="eyebrow">Descuentos</p>
                    <h2 id="discount-benefit-modal-title" data-discount-benefit-modal-title>Agregar beneficio</h2>
                </div>
                <button class="button button--secondary" type="button" data-discount-benefit-close aria-label="Cerrar">X</button>
            </div>

            <p class="discount-benefit-help">No guardes numeros de tarjeta ni informacion bancaria sensible.</p>
            <p class="task-message" data-discount-benefit-message hidden></p>

            <form class="task-form discount-benefit-form" data-discount-benefit-form>
                <input type="hidden" name="id" value="">
                <input type="hidden" name="mode" value="existing">

                <div class="discount-benefit-modes" data-discount-benefit-modes>
                    <button class="quick-filter is-active" type="button" data-discount-benefit-mode="existing">Elegir existente</button>
                    <button class="quick-filter" type="button" data-discount-benefit-mode="create">No encuentro mi beneficio</button>
                </div>

                <div class="discount-benefit-existing" data-discount-benefit-existing>
                    <?php if ($benefitPrograms !== []): ?>
                        <label>
                            Buscar en catalogo
                            <input type="search" name="program_search" placeholder="Banco, tarjeta, operador o producto" data-benefit-program-search>
                        </label>
                        <label>
                            Beneficio
                            <select name="benefit_program_id" data-benefit-program-select>
                                <option value="">Selecciona un beneficio</option>
                                <?php foreach ($benefitPrograms as $program): ?>
                                    <?php
                                    $programProduct = is_string($program['product_name'] ?? null) ? trim((string) $program['product_name']) : '';
                                    $programLabel = (string) ($program['provider_name'] ?? '') . ' - ' . (string) ($program['name'] ?? '');

                                    if ($programProduct !== '') {
                                        $programLabel .= ' - ' . $programProduct;
                                    }
                                    ?>
                                    <option
                                        value="<?= View::escape((string) ($program['id'] ?? '')) ?>"
                                        data-search="<?= View::escape(strtolower($programLabel . ' ' . ($program['benefit_type'] ?? ''))) ?>"
                                    >
                                        <?= View::escape($programLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php else: ?>
                        <div class="discount-benefit-empty-catalog">
                            <h3>El catalogo aun esta vacio.</h3>
                            <p>Crea tu primer beneficio y quedara disponible para reutilizarlo despues.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="discount-benefit-create" data-discount-benefit-create hidden>
                    <div class="task-form__grid task-form__grid--discount-benefit">
                        <label>
                            Proveedor *
                            <input name="provider_name" type="text" maxlength="180" placeholder="Banco de Chile">
                        </label>
                        <label>
                            Nombre del beneficio *
                            <input name="name" type="text" maxlength="180" placeholder="Tarjetas Banco de Chile">
                        </label>
                        <label>
                            Tipo *
                            <select name="benefit_type">
                                <?php foreach ($benefitTypeLabels as $type => $label): ?>
                                    <option value="<?= View::escape((string) $type) ?>"><?= View::escape((string) $label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Producto especifico opcional
                            <input name="product_name" type="text" maxlength="180" placeholder="Visa">
                        </label>
                    </div>
                </div>

                <div class="task-form__grid task-form__grid--discount-user-benefit" data-discount-user-fields>
                    <label>
                        Nombre personalizado opcional
                        <input name="nickname" type="text" maxlength="180" placeholder="Mi Visa">
                    </label>
                    <label>
                        Notas opcional
                        <textarea name="notes" rows="3" maxlength="5000" placeholder="La uso principalmente para compras online."></textarea>
                    </label>
                </div>

                <div class="task-form__actions">
                    <button class="button button--secondary" type="button" data-discount-benefit-close>Cancelar</button>
                    <button class="button button--primary" type="submit" data-discount-benefit-submit>Guardar</button>
                </div>
            </form>
        </div>
    </section>
</section>
