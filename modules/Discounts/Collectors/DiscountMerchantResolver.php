<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

use Modules\Discounts\DiscountMerchantRepository;
use Modules\Discounts\DiscountMerchantService;
use Modules\Discounts\DiscountTextNormalizer;

final class DiscountMerchantResolver
{
    public function __construct(
        private readonly DiscountMerchantRepository $merchants,
        private readonly DiscountMerchantService $merchantService,
        private readonly DiscountTextNormalizer $text = new DiscountTextNormalizer(),
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(?string $merchantName, ?string $category = null, bool $allowCreate = true): ?array
    {
        $merchantName = $this->text->cleanVisible($merchantName);

        if ($merchantName === null) {
            return null;
        }

        $comparable = $this->text->comparable($merchantName);

        foreach ($this->merchants->list() as $merchant) {
            if ($this->text->comparable((string) ($merchant['name'] ?? '')) === $comparable) {
                return $merchant;
            }
        }

        if (!$allowCreate) {
            return null;
        }

        return $this->merchantService->createOrFind([
            'name' => $merchantName,
            'category' => $category,
            'website_url' => null,
            'active' => true,
        ]);
    }
}
