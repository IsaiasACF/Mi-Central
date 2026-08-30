<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Support\View;
use Modules\Discounts\DiscountPromotionFormat;

$activeTab = is_string($activeTab ?? null) ? $activeTab : 'for-me';
$promotions = is_array($promotions ?? null) ? $promotions : [];
$merchants = is_array($merchants ?? null) ? $merchants : [];
$benefitPrograms = is_array($benefitPrograms ?? null) ? $benefitPrograms : [];
$availableCategories = is_array($availableCategories ?? null) ? $availableCategories : [];
$promotionLabels = is_array($promotionLabels ?? null) ? $promotionLabels : [
    'discount_types' => DiscountPromotionFormat::discountTypeLabels(),
    'channels' => DiscountPromotionFormat::channelLabels(),
    'weekdays' => DiscountPromotionFormat::WEEKDAYS,
    'categories' => [],
];
$filters = is_array($filters ?? null) ? $filters : [];
$userBenefitCount = (int) ($userBenefitCount ?? 0);
$error = is_string($error ?? null) ? (string) $error : '';
$searchFilter = is_string($filters['search'] ?? null) ? (string) $filters['search'] : '';
$merchantFilter = is_string($filters['merchant_id'] ?? null) ? (string) $filters['merchant_id'] : '';
$benefitFilter = is_string($filters['benefit_program_id'] ?? null) ? (string) $filters['benefit_program_id'] : '';
$channelFilter = is_string($filters['channel'] ?? null) ? (string) $filters['channel'] : '';
$categoryFilter = is_string($filters['category'] ?? null) ? (string) $filters['category'] : '';
$collectorFilter = is_string($filters['collector_key'] ?? null) ? (string) $filters['collector_key'] : '';
$stateFilter = is_string($filters['state'] ?? null) ? (string) $filters['state'] : 'available';
$collectorLabels = [
    'banco_chile' => 'Banco de Chile',
    'santander_chile' => 'Santander',
    'bancoestado' => 'BancoEstado',
];
$titles = [
    'for-me' => [
        'title' => 'Para mi',
        'description' => 'Descuentos recolectados que calzan con tus tarjetas y beneficios.',
        'empty_title' => 'No encontramos promociones compatibles por ahora.',
        'empty_text' => 'Revisa tus tarjetas y beneficios o vuelve mas adelante.',
    ],
    'all' => [
        'title' => 'Todos',
        'description' => 'Catalogo recolectado desde fuentes oficiales.',
        'empty_title' => 'Aun no hay promociones disponibles.',
        'empty_text' => 'Los recolectores incorporaran promociones cuando encuentren fuentes disponibles.',
    ],
    'favorites' => [
        'title' => 'Favoritos',
        'description' => 'Promociones guardadas para revisarlas despues.',
        'empty_title' => 'Todavia no tienes favoritos.',
        'empty_text' => 'Marca el corazon de una promocion para guardarla aqui.',
    ],
];
$copy = $titles[$activeTab] ?? $titles['for-me'];
?>
<section
    class="discounts-page discounts-discovery-page"
    aria-labelledby="page-title"
    data-discounts-discovery-page
    data-api-url="/api/discounts/favorites.php"
    data-csrf-token="<?= View::escape(Csrf::token()) ?>"
>
    <section class="page-heading discounts-heading">
        <div>
            <p class="eyebrow">Descuentos</p>
            <h1 id="page-title"><?= View::escape($copy['title']) ?></h1>
            <p><?= View::escape($copy['description']) ?></p>
        </div>
        <?php if ($activeTab === 'for-me' && $userBenefitCount === 0): ?>
            <a class="button button--secondary" href="/index.php?section=settings&amp;tab=benefits">Administrar mis tarjetas y beneficios</a>
        <?php endif; ?>
    </section>

    <?php View::render('components/discounts-tabs', ['activeTab' => $activeTab]); ?>

    <?php if ($activeTab === 'for-me' && $userBenefitCount === 0): ?>
        <aside class="discount-discovery-notice">
            <p>Marca tus tarjetas y beneficios en tu perfil para encontrar mas promociones compatibles.</p>
            <a href="/index.php?section=settings&amp;tab=benefits">Mis tarjetas y beneficios</a>
        </aside>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <p class="task-message task-message--error" role="alert"><?= View::escape($error) ?></p>
    <?php endif; ?>

    <section class="tasks-panel discounts-discovery-panel" aria-label="<?= View::escape($copy['title']) ?>">
        <form class="task-filters task-filters--discount-discovery" method="get" action="/index.php">
            <input type="hidden" name="section" value="discounts">
            <?php if ($activeTab !== 'for-me'): ?>
                <input type="hidden" name="tab" value="<?= View::escape($activeTab) ?>">
            <?php endif; ?>

            <label>
                Buscar
                <input name="search" type="search" value="<?= View::escape($searchFilter) ?>" placeholder="Titulo o comercio">
            </label>

            <?php if ($merchants !== []): ?>
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
            <?php endif; ?>

            <label>
                Canal
                <select name="channel">
                    <option value="">Todos</option>
                    <option value="in_store" <?= $channelFilter === 'in_store' ? 'selected' : '' ?>>Presencial</option>
                    <option value="online" <?= $channelFilter === 'online' ? 'selected' : '' ?>>Online</option>
                    <option value="both" <?= $channelFilter === 'both' ? 'selected' : '' ?>>Ambos</option>
                </select>
            </label>

            <?php if ($availableCategories !== []): ?>
                <label>
                    Categoria
                    <select name="category">
                        <option value="">Todas</option>
                        <?php foreach ($availableCategories as $category): ?>
                            <option value="<?= View::escape((string) $category) ?>" <?= $categoryFilter === (string) $category ? 'selected' : '' ?>>
                                <?= View::escape((string) ($promotionLabels['categories'][$category] ?? $category)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <?php if ($activeTab === 'all' && $benefitPrograms !== []): ?>
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
            <?php endif; ?>

            <?php if ($activeTab === 'all'): ?>
                <label>
                    Fuente
                    <select name="collector_key">
                        <option value="">Todas</option>
                        <?php foreach ($collectorLabels as $collectorKey => $collectorLabel): ?>
                            <option value="<?= View::escape($collectorKey) ?>" <?= $collectorFilter === $collectorKey ? 'selected' : '' ?>>
                                <?= View::escape($collectorLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <?php if ($activeTab === 'all'): ?>
                <label>
                    Estado
                    <select name="state">
                        <option value="available" <?= $stateFilter === 'available' ? 'selected' : '' ?>>Vigentes y proximas</option>
                        <option value="current" <?= $stateFilter === 'current' ? 'selected' : '' ?>>Vigentes</option>
                        <option value="upcoming" <?= $stateFilter === 'upcoming' ? 'selected' : '' ?>>Proximas</option>
                        <option value="expired" <?= $stateFilter === 'expired' ? 'selected' : '' ?>>Vencidas</option>
                        <option value="all" <?= $stateFilter === 'all' ? 'selected' : '' ?>>Todas</option>
                    </select>
                </label>
            <?php endif; ?>

            <button class="button button--secondary" type="submit">Filtrar</button>
        </form>

        <div class="tasks-list-header discounts-list-header">
            <div>
                <h2><?= View::escape($copy['title']) ?></h2>
                <p class="muted"><?= View::escape((string) count($promotions)) ?> <?= count($promotions) === 1 ? 'promocion' : 'promociones' ?></p>
            </div>
        </div>

        <p class="task-message" data-discount-favorite-message hidden></p>

        <?php if ($promotions !== []): ?>
            <div class="discount-discovery-grid">
                <?php foreach ($promotions as $promotion): ?>
                    <?php View::render('components/discount-promotion-card', [
                        'promotion' => $promotion,
                        'promotionLabels' => $promotionLabels,
                    ]); ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="discount-empty-state">
                <h2><?= View::escape($copy['empty_title']) ?></h2>
                <p><?= View::escape($copy['empty_text']) ?></p>
                <?php if ($activeTab === 'for-me'): ?>
                    <a class="button button--primary" href="/index.php?section=settings&amp;tab=benefits">Mis tarjetas y beneficios</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
