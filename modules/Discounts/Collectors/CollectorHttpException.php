<?php
declare(strict_types=1);

namespace Modules\Discounts\Collectors;

final class CollectorHttpException extends CollectorException
{
    public function __construct(string $message, private readonly ?int $statusCode = null)
    {
        parent::__construct($message);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }
}
