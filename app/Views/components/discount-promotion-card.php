<?php
declare(strict_types=1);

use App\Support\View;
use Modules\Discounts\DiscountCompatibilityService;
use Modules\Discounts\DiscountPromotionFormat;

$promotion = is_array($promotion ?? null) ? $promotion : [];
$promotionLabels = is_array($promotionLabels ?? null) ? $promotionLabels : [];
$weekdayLabels = is_array($promotionLabels['weekdays'] ?? null) ? $promotionLabels['weekdays'] : DiscountPromotionFormat::WEEKDAYS;
$channelLabels = [
    'in_store' => 'Presencial',
    'online' => 'Online',
    'both' => 'Presencial y online',
];
$promotionId = (int) ($promotion['id'] ?? 0);
$merchantName = is_string($promotion['merchant_name'] ?? null) && trim((string) $promotion['merchant_name']) !== ''
    ? trim((string) $promotion['merchant_name'])
    : 'Sin comercio';
$discountLabel = is_string($promotion['discount_label'] ?? null) ? trim((string) $promotion['discount_label']) : '';
$availabilityState = is_string($promotion['availability_state'] ?? null) ? (string) $promotion['availability_state'] : '';
$validityLabel = is_string($promotion['validity_label'] ?? null) ? (string) $promotion['validity_label'] : '';
$compatibility = is_array($promotion['compatibility'] ?? null) ? $promotion['compatibility'] : [];
$compatibilityStatus = (string) ($compatibility['status'] ?? DiscountCompatibilityService::STATUS_INCOMPATIBLE);
$compatibilityReason = is_string($compatibility['reason'] ?? null) ? (string) $compatibility['reason'] : '';
$matchedBenefits = is_array($compatibility['matched_benefits'] ?? null) ? $compatibility['matched_benefits'] : [];
$requiredBenefits = is_array($compatibility['required_benefits'] ?? null) ? $compatibility['required_benefits'] : [];
$matchedIds = [];

foreach ($matchedBenefits as $benefit) {
    $matchedIds[(int) ($benefit['id'] ?? 0)] = true;
}

$benefits = is_array($promotion['benefits'] ?? null) ? $promotion['benefits'] : [];
$weekdays = is_array($promotion['weekdays'] ?? null) ? array_values(array_map('intval', $promotion['weekdays'])) : [];
$weekdayShort = [
    1 => 'Lun',
    2 => 'Mar',
    3 => 'Mie',
    4 => 'Jue',
    5 => 'Vie',
    6 => 'Sab',
    7 => 'Dom',
];
$weekdayLabel = $weekdays === []
    ? 'Todos los dias'
    : implode(' · ', array_map(static fn (int $weekday): string => $weekdayShort[$weekday] ?? (string) ($weekdayLabels[$weekday] ?? $weekday), $weekdays));
$channel = (string) ($promotion['channel'] ?? 'both');
$channelLabel = $channelLabels[$channel] ?? 'Presencial y online';
$isFavorite = (bool) ($promotion['is_favorite'] ?? false);
$terms = is_string($promotion['terms'] ?? null) ? trim((string) $promotion['terms']) : '';
$promoCode = is_string($promotion['promo_code'] ?? null) ? trim((string) $promotion['promo_code']) : '';
$description = is_string($promotion['description'] ?? null) ? trim((string) $promotion['description']) : '';
$conditionsId = 'discount-conditions-' . $promotionId;
$availabilityClass = strtolower(str_replace(' ', '-', $availabilityState));
$sourceUrl = is_string($promotion['source_url'] ?? null) ? trim((string) $promotion['source_url']) : '';
$sourceScheme = $sourceUrl !== '' ? strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME)) : '';
$hasOfficialUrl = in_array($sourceScheme, ['http', 'https'], true);
$collectorLabels = [
    'banco_chile' => 'Banco de Chile',
    'santander_chile' => 'Santander',
    'bancoestado' => 'BancoEstado',
];
$collectorKey = is_string($promotion['collector_key'] ?? null) ? (string) $promotion['collector_key'] : '';
$sourceLabel = $collectorLabels[$collectorKey] ?? '';
$category = is_string($promotion['category'] ?? null) ? (string) $promotion['category'] : '';
$categoryLabel = is_string($promotionLabels['categories'][$category] ?? null) ? (string) $promotionLabels['categories'][$category] : '';

$compatibilityTitle = match ($compatibilityStatus) {
    DiscountCompatibilityService::STATUS_GENERAL => 'Para todos',
    DiscountCompatibilityService::STATUS_COMPATIBLE => 'Te sirve',
    default => 'Requiere beneficio',
};

$compatibilityText = match ($compatibilityStatus) {
    DiscountCompatibilityService::STATUS_GENERAL => 'Disponible para todos',
    DiscountCompatibilityService::STATUS_COMPATIBLE => $compatibilityReason !== '' ? $compatibilityReason : 'Te sirve',
    default => $requiredBenefits === []
        ? 'No compatible'
        : 'Requiere ' . implode(' o ', array_map(static fn (array $benefit): string => (string) ($benefit['label'] ?? $benefit['name'] ?? 'Beneficio'), $requiredBenefits)),
};
?>
<article
    class="discount-promo-card <?= $availabilityState === 'Vencida' || $availabilityState === 'Inactiva' ? 'is-unavailable' : '' ?>"
    data-discount-card
    data-promotion-id="<?= View::escape((string) $promotionId) ?>"
>
    <div class="discount-promo-card__topline">
        <div>
            <p class="discount-benefit-card__provider"><?= View::escape($merchantName) ?></p>
            <h3 class="discount-promo-card__title"><?= View::escape($promotion['title'] ?? '') ?></h3>
        </div>
        <button
            class="discount-favorite-button <?= $isFavorite ? 'is-favorite' : '' ?>"
            type="button"
            data-discount-favorite
            data-promotion-id="<?= View::escape((string) $promotionId) ?>"
            data-favorite="<?= $isFavorite ? '1' : '0' ?>"
            aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"
            aria-label="<?= $isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
            title="<?= $isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?>"
        >
            <span aria-hidden="true" data-discount-favorite-icon><?= $isFavorite ? '♥' : '♡' ?></span>
            <span class="sr-only" data-discount-favorite-text><?= $isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos' ?></span>
        </button>
    </div>

    <?php if ($sourceLabel !== ''): ?>
        <p class="discount-promo-card__source"><?= View::escape($sourceLabel) ?></p>
    <?php endif; ?>

    <div class="discount-promo-card__badges">
        <?php if ($discountLabel !== ''): ?>
            <span class="discount-promo-badge discount-promo-badge--discount discount-promo-card__discount"><?= View::escape($discountLabel) ?></span>
        <?php endif; ?>
        <span class="discount-compatibility-badge discount-compatibility-badge--<?= View::escape($compatibilityStatus) ?>">
            <?= View::escape($compatibilityTitle) ?>
        </span>
        <?php if ($availabilityState !== ''): ?>
            <span class="discount-availability-badge discount-availability-badge--<?= View::escape($availabilityClass) ?>">
                <?= View::escape($availabilityState) ?>
            </span>
        <?php endif; ?>
        <?php if ($categoryLabel !== ''): ?>
            <span class="discount-availability-badge discount-availability-badge--category"><?= View::escape($categoryLabel) ?></span>
        <?php endif; ?>
        <?php if (($promotion['applicable_today'] ?? false) === true): ?>
            <span class="discount-availability-badge discount-availability-badge--today">Hoy</span>
        <?php endif; ?>
    </div>

    <p class="discount-promo-card__compatibility"><?= View::escape($compatibilityText) ?></p>

    <div class="discount-promo-card__meta">
        <?php if ($validityLabel !== ''): ?>
            <span><?= View::escape($validityLabel) ?></span>
        <?php endif; ?>
        <span><?= View::escape($weekdayLabel) ?></span>
        <span><?= View::escape($channelLabel) ?></span>
        <?php if ($promoCode !== ''): ?>
            <span>Codigo: <?= View::escape($promoCode) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($benefits !== []): ?>
        <div class="discount-promo-card__benefits" aria-label="Beneficios requeridos">
            <?php foreach ($benefits as $benefit): ?>
                <?php
                $benefitId = (int) ($benefit['id'] ?? 0);
                $benefitName = trim((string) ($benefit['name'] ?? ''));
                $benefitProvider = trim((string) ($benefit['provider_name'] ?? ''));
                $benefitLabel = $benefitName !== '' ? $benefitName : ($benefitProvider !== '' ? $benefitProvider : 'Beneficio');
                ?>
                <span class="discount-benefit-chip <?= isset($matchedIds[$benefitId]) ? 'is-matched' : '' ?>">
                    <?= View::escape($benefitLabel) ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($description !== ''): ?>
        <p class="discount-promo-card__description"><?= View::escape($description) ?></p>
    <?php endif; ?>

    <?php if ($terms !== '' || $hasOfficialUrl): ?>
        <div class="discount-promo-card__footer">
            <?php if ($terms !== ''): ?>
                <div class="discount-promo-card__conditions">
                    <button
                        class="button button--secondary discount-conditions-toggle"
                        type="button"
                        data-discount-conditions-toggle
                        data-target="<?= View::escape($conditionsId) ?>"
                        aria-expanded="false"
                        aria-controls="<?= View::escape($conditionsId) ?>"
                    >
                        Ver condiciones
                    </button>
                    <p id="<?= View::escape($conditionsId) ?>" class="discount-promo-card__terms" hidden>
                        <?= View::escape($terms) ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($hasOfficialUrl): ?>
                <div class="discount-promo-card__actions">
                    <a class="button button--secondary" href="<?= View::escape($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">Ver promocion oficial</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>
