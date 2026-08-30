<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

final class PromotionDeduplicationDecision
{
    /**
     * @param array<int, string> $warnings
     */
    public function __construct(
        private readonly string $action,
        private readonly ?int $promotionId = null,
        private readonly string $matchedBy = 'none',
        private readonly array $warnings = [],
    ) {
    }

    public static function create(array $warnings = []): self
    {
        return new self('create', null, 'none', $warnings);
    }

    public static function update(int $promotionId, string $matchedBy, array $warnings = []): self
    {
        return new self('update', $promotionId, $matchedBy, $warnings);
    }

    public function action(): string
    {
        return $this->action;
    }

    public function promotionId(): ?int
    {
        return $this->promotionId;
    }

    public function matchedBy(): string
    {
        return $this->matchedBy;
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
