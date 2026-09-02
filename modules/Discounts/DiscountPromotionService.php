<?php
declare(strict_types=1);

namespace Modules\Discounts;

use DateTimeImmutable;

final class DiscountPromotionService
{
    private const DISCOUNT_TYPES = DiscountPromotionFormat::DISCOUNT_TYPES;
    private const CHANNELS = DiscountPromotionFormat::CHANNELS;
    private const SOURCE_TYPES = ['manual', 'collector'];
    private const TITLE_MAX_LENGTH = 220;
    private const TEXT_MAX_LENGTH = 5000;
    private const SHORT_TEXT_MAX_LENGTH = 255;
    private const URL_MAX_LENGTH = 500;

    public function __construct(
        private readonly DiscountPromotionRepository $promotions,
        private readonly DiscountMerchantService $merchants,
        private readonly DiscountBenefitProgramService $programs,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $merchantId = $this->optionalPositiveId($input['merchant_id'] ?? null, 'merchant_id');

        if ($merchantId !== null) {
            $this->merchants->assertExists($merchantId);
        }

        $startsOn = $this->optionalDate($input['starts_on'] ?? null, 'starts_on');
        $endsOn = $this->optionalDate($input['ends_on'] ?? null, 'ends_on');

        if ($startsOn !== null && $endsOn !== null && $startsOn > $endsOn) {
            throw new DiscountValidationException('Vigencia invalida.');
        }

        $id = $this->promotions->create([
            'merchant_id' => $merchantId,
            'created_by_user_id' => $this->optionalPositiveId($input['created_by_user_id'] ?? null, 'created_by_user_id'),
            'category' => $this->optionalCategory($input['category'] ?? null),
            'title' => $this->requiredText($input['title'] ?? null, 'title', self::TITLE_MAX_LENGTH),
            'description' => $this->optionalText($input['description'] ?? null, self::TEXT_MAX_LENGTH),
            'discount_type' => $this->discountType($input['discount_type'] ?? null),
            'discount_value' => $this->optionalDiscountValue($input['discount_value'] ?? null),
            'max_discount_clp' => $this->optionalPositiveInt($input['max_discount_clp'] ?? null, 'max_discount_clp'),
            'promo_code' => $this->optionalText($input['promo_code'] ?? null, 120),
            'channel' => $this->channel($input['channel'] ?? 'both'),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'terms' => $this->optionalText($input['terms'] ?? null, self::TEXT_MAX_LENGTH),
            'source_type' => $this->sourceType($input['source_type'] ?? 'manual'),
            'collector_key' => $this->optionalCollectorKey($input['collector_key'] ?? null),
            'source_key' => $this->optionalText($input['source_key'] ?? null, self::SHORT_TEXT_MAX_LENGTH),
            'source_url' => $this->optionalUrl($input['source_url'] ?? null),
            'last_seen_at' => $this->optionalDateTime($input['last_seen_at'] ?? null, 'last_seen_at'),
            'dedupe_fingerprint' => $this->optionalFingerprint($input['dedupe_fingerprint'] ?? null),
            'is_active' => $this->boolFlag($input['is_active'] ?? true),
        ]);

        return $this->get($id) ?? [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createManual(int $userId, array $input): array
    {
        return $this->promotions->transaction(function () use ($userId, $input): array {
            $data = $this->manualPromotionData($userId, $input);
            $benefitIds = $this->benefitIds($input['benefit_program_ids'] ?? []);
            $weekdays = $this->weekdays($input['weekdays'] ?? []);
            $id = $this->promotions->create($data);
            $this->promotions->replaceBenefits($id, $benefitIds);
            $this->promotions->replaceWeekdays($id, $weekdays);

            return $this->getManual($userId, $id) ?? [];
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function updateManual(int $userId, int $promotionId, array $input): ?array
    {
        $promotionId = $this->positiveId($promotionId, 'id');

        return $this->promotions->transaction(function () use ($userId, $promotionId, $input): ?array {
            if ($this->promotions->findManualForUser($userId, $promotionId) === null) {
                return null;
            }

            $this->promotions->update($promotionId, $this->manualPromotionData($userId, $input));
            $this->promotions->replaceBenefits($promotionId, $this->benefitIds($input['benefit_program_ids'] ?? []));
            $this->promotions->replaceWeekdays($promotionId, $this->weekdays($input['weekdays'] ?? []));

            return $this->getManual($userId, $promotionId);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getManual(int $userId, int $promotionId): ?array
    {
        $promotion = $this->promotions->findManualForUser($userId, $this->positiveId($promotionId, 'id'));

        return $promotion === null ? null : $this->withRelations($promotion);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $promotionId): ?array
    {
        return $this->promotions->findById($this->positiveId($promotionId, 'id'));
    }

    public function addBenefit(int $promotionId, int $benefitProgramId): void
    {
        $promotionId = $this->positiveId($promotionId, 'promotion_id');
        $benefitProgramId = $this->positiveId($benefitProgramId, 'benefit_program_id');
        $this->assertPromotionExists($promotionId);
        $this->programs->assertExists($benefitProgramId);

        if ($this->promotions->promotionHasBenefit($promotionId, $benefitProgramId)) {
            throw new DiscountValidationException('La promocion ya tiene ese beneficio asociado.');
        }

        $this->promotions->addBenefit($promotionId, $benefitProgramId);
    }

    public function addWeekday(int $promotionId, int $weekday): void
    {
        $promotionId = $this->positiveId($promotionId, 'promotion_id');
        $weekday = $this->weekday($weekday);
        $this->assertPromotionExists($promotionId);

        if ($this->promotions->promotionHasWeekday($promotionId, $weekday)) {
            throw new DiscountValidationException('La promocion ya tiene ese dia asociado.');
        }

        $this->promotions->addWeekday($promotionId, $weekday);
    }

    public function addFavorite(int $userId, int $promotionId): void
    {
        $promotionId = $this->positiveId($promotionId, 'promotion_id');
        $this->assertPromotionExists($promotionId);

        if ($this->promotions->favoriteExists($userId, $promotionId)) {
            throw new DiscountValidationException('La promocion ya esta en favoritos.');
        }

        $this->promotions->addFavorite($userId, $promotionId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        return $this->promotions->listActive();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listManual(int $userId, array $filters = []): array
    {
        $filters = $this->manualFilters($filters);

        return array_map(fn (array $promotion): array => $this->withRelations($promotion), $this->promotions->listManualForUser($userId, $filters));
    }

    public function setManualActive(int $userId, int $promotionId, bool $active): ?array
    {
        $promotionId = $this->positiveId($promotionId, 'id');

        if (!$this->promotions->setActiveManualForUser($userId, $promotionId, $active) && $this->promotions->findManualForUser($userId, $promotionId) === null) {
            return null;
        }

        return $this->getManual($userId, $promotionId);
    }

    public function deleteManual(int $userId, int $promotionId): bool
    {
        return $this->promotions->deleteManualForUser($userId, $this->positiveId($promotionId, 'id'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByMerchant(int $merchantId): array
    {
        $merchantId = $this->positiveId($merchantId, 'merchant_id');
        $this->merchants->assertExists($merchantId);

        return $this->promotions->listByMerchant($merchantId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByBenefit(int $benefitProgramId): array
    {
        $benefitProgramId = $this->positiveId($benefitProgramId, 'benefit_program_id');
        $this->programs->assertExists($benefitProgramId);

        return $this->promotions->listByBenefit($benefitProgramId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCurrentForDate(string $date, int $weekday): array
    {
        return $this->promotions->listCurrentForDate($this->date($date, 'date'), $this->weekday($weekday));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFavorites(int $userId): array
    {
        return $this->promotions->listFavoritesForUser($userId);
    }

    private function assertPromotionExists(int $promotionId): void
    {
        if ($this->get($promotionId) === null) {
            throw new DiscountValidationException('Promocion no encontrada.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function manualPromotionData(int $userId, array $input): array
    {
        $discountType = $this->discountType($input['discount_type'] ?? null);
        $startsOn = $this->optionalDate($input['starts_on'] ?? null, 'starts_on');
        $endsOn = $this->optionalDate($input['ends_on'] ?? null, 'ends_on');

        if ($startsOn !== null && $endsOn !== null && $startsOn > $endsOn) {
            throw new DiscountValidationException('Vigencia invalida.');
        }

        return [
            'merchant_id' => $this->merchantId($input),
            'created_by_user_id' => $userId,
            'category' => $this->optionalCategory($input['category'] ?? null),
            'title' => $this->requiredText($input['title'] ?? null, 'title', self::TITLE_MAX_LENGTH),
            'description' => $this->optionalText($input['description'] ?? null, self::TEXT_MAX_LENGTH),
            'discount_type' => $discountType,
            'discount_value' => $this->discountValueForType($discountType, $input['discount_value'] ?? null),
            'max_discount_clp' => $this->optionalPositiveInt($input['max_discount_clp'] ?? null, 'max_discount_clp'),
            'promo_code' => $this->optionalText($input['promo_code'] ?? null, 120),
            'channel' => $this->channel($input['channel'] ?? 'both'),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'terms' => $this->optionalText($input['terms'] ?? null, self::TEXT_MAX_LENGTH),
            'source_type' => 'manual',
            'collector_key' => null,
            'source_key' => null,
            'source_url' => $this->optionalUrl($input['source_url'] ?? null),
            'last_seen_at' => null,
            'dedupe_fingerprint' => null,
            'is_active' => $this->boolFlag($input['is_active'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function merchantId(array $input): ?int
    {
        $merchantMode = is_string($input['merchant_mode'] ?? null) ? (string) $input['merchant_mode'] : 'existing';
        $merchantName = $this->optionalText($input['merchant_name'] ?? null, 180);

        if ($merchantMode === 'create' || $merchantName !== null) {
            $merchant = $this->merchants->createOrFind([
                'name' => $merchantName,
                'category' => $input['merchant_category'] ?? null,
                'website_url' => $input['merchant_website_url'] ?? null,
            ]);

            return (int) $merchant['id'];
        }

        $merchantId = $this->optionalPositiveId($input['merchant_id'] ?? null, 'merchant_id');

        if ($merchantId !== null) {
            $this->merchants->assertExists($merchantId);
        }

        return $merchantId;
    }

    private function discountValueForType(string $type, mixed $value): ?string
    {
        if ($type === 'special_price' || $type === 'other') {
            return null;
        }

        if ($value === null || $value === '') {
            throw new DiscountValidationException('Valor de descuento invalido.');
        }

        if (!is_numeric($value)) {
            throw new DiscountValidationException('Valor de descuento invalido.');
        }

        $floatValue = (float) $value;

        if ($type === 'percentage' && ($floatValue <= 0 || $floatValue > 100)) {
            throw new DiscountValidationException('Porcentaje invalido.');
        }

        if ($type === 'fixed_amount' && $floatValue <= 0) {
            throw new DiscountValidationException('Monto invalido.');
        }

        return number_format($floatValue, 2, '.', '');
    }

    private function optionalCategory(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $category = trim((string) $value);

        if (!DiscountPromotionCategory::isValid($category)) {
            throw new DiscountValidationException('Categoria invalida.');
        }

        return $category;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function benefitIds(mixed $value): array
    {
        $ids = $this->idList($value, 'benefit_program_ids');

        foreach ($ids as $benefitProgramId) {
            $this->programs->assertExists($benefitProgramId);
        }

        return $ids;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function weekdays(mixed $value): array
    {
        return array_map(fn (int $weekday): int => $this->weekday($weekday), $this->idList($value, 'weekdays'));
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function idList(mixed $value, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '');
        }

        if (!is_array($value)) {
            throw new DiscountValidationException('Lista invalida: ' . $field . '.');
        }

        $ids = [];

        foreach ($value as $item) {
            if (is_int($item)) {
                $id = $item;
            } elseif (is_string($item) && preg_match('/\A[1-9][0-9]*\z/', $item) === 1) {
                $id = (int) $item;
            } else {
                throw new DiscountValidationException('Lista invalida: ' . $field . '.');
            }

            if (isset($ids[$id])) {
                throw new DiscountValidationException('Relacion duplicada: ' . $field . '.');
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $promotion
     * @return array<string, mixed>
     */
    private function withRelations(array $promotion): array
    {
        $promotionId = (int) $promotion['id'];
        $promotion['benefits'] = $this->promotions->listBenefits($promotionId);
        $promotion['weekdays'] = $this->promotions->listWeekdays($promotionId);
        $promotion['discount_label'] = DiscountPromotionFormat::discountLabel($promotion);
        $promotion['validity_label'] = DiscountPromotionFormat::validityLabel($promotion);
        $promotion['temporal_state'] = DiscountPromotionFormat::temporalState($promotion);
        $promotion['applicable_today'] = (new DiscountPromotionAvailabilityService())->isApplicableToday($promotion, $promotion['weekdays']);

        return $promotion;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function manualFilters(array $filters): array
    {
        $status = is_string($filters['status'] ?? null) ? (string) $filters['status'] : 'active';

        if (!in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }

        $normalized = ['status' => $status];

        foreach (['merchant_id', 'benefit_program_id', 'weekday'] as $field) {
            if (($filters[$field] ?? '') === '' || ($filters[$field] ?? null) === null) {
                continue;
            }

            $normalized[$field] = $this->positiveId((int) $filters[$field], $field);
        }

        if (is_string($filters['search'] ?? null)) {
            $normalized['search'] = trim((string) $filters['search']);
        }

        return $normalized;
    }

    private function discountType(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (!in_array($value, self::DISCOUNT_TYPES, true)) {
            throw new DiscountValidationException('Tipo de descuento invalido.');
        }

        return $value;
    }

    private function channel(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (!in_array($value, self::CHANNELS, true)) {
            throw new DiscountValidationException('Canal invalido.');
        }

        return $value;
    }

    private function sourceType(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (!in_array($value, self::SOURCE_TYPES, true)) {
            throw new DiscountValidationException('Tipo de fuente invalido.');
        }

        return $value;
    }

    private function optionalCollectorKey(mixed $value): ?string
    {
        $value = $this->optionalText($value, 120);

        if ($value === null) {
            return null;
        }

        if (preg_match('/\A[a-z0-9][a-z0-9_]{1,80}\z/', $value) !== 1) {
            throw new DiscountValidationException('Collector key invalida.');
        }

        return $value;
    }

    private function optionalFingerprint(mixed $value): ?string
    {
        $value = $this->optionalText($value, 64);

        if ($value === null) {
            return null;
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new DiscountValidationException('Fingerprint invalido.');
        }

        return $value;
    }

    private function weekday(int $weekday): int
    {
        if ($weekday < 1 || $weekday > 7) {
            throw new DiscountValidationException('Dia de semana invalido.');
        }

        return $weekday;
    }

    private function requiredText(mixed $value, string $field, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($value === '' || $length > $maxLength) {
            throw new DiscountValidationException('Texto invalido: ' . $field . '.');
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        if ($value === '') {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($length > $maxLength) {
            throw new DiscountValidationException('Texto demasiado largo.');
        }

        return $value;
    }

    private function optionalUrl(mixed $value): ?string
    {
        $value = $this->optionalText($value, self::URL_MAX_LENGTH);

        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new DiscountValidationException('URL invalida.');
        }

        return $value;
    }

    private function optionalDiscountValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (float) $value < 0) {
            throw new DiscountValidationException('Valor de descuento invalido.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $intValue = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            $intValue = (int) $value;
        } else {
            throw new DiscountValidationException('Numero invalido: ' . $field . '.');
        }

        if ($intValue < 0) {
            throw new DiscountValidationException('Numero invalido: ' . $field . '.');
        }

        return $intValue;
    }

    private function optionalPositiveId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            $id = (int) $value;
        } else {
            throw new DiscountValidationException('Identificador invalido: ' . $field . '.');
        }

        return $this->positiveId($id, $field);
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->date((string) $value, $field);
    }

    private function date(string $value, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new DiscountValidationException('Fecha invalida: ' . $field . '.');
        }

        return $value;
    }

    private function optionalDateTime(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace('T', ' ', trim((string) $value));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);

        if (!$date instanceof DateTimeImmutable) {
            throw new DiscountValidationException('Fecha invalida: ' . $field . '.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function boolFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        if (is_string($value) && in_array($value, ['0', '1'], true)) {
            return (int) $value;
        }

        throw new DiscountValidationException('Estado invalido.');
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new DiscountValidationException('Identificador invalido: ' . $field . '.');
        }

        return $value;
    }
}
