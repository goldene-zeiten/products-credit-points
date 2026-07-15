<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Domain\Dto;

use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class CreditPointsRedemption
{
    public function __construct(
        private int $points,
        private Money $discountAmount
    ) {}

    public function getPoints(): int
    {
        return $this->points;
    }

    public function getDiscountAmount(): Money
    {
        return $this->discountAmount;
    }

    public function isEmpty(): bool
    {
        return $this->points === 0;
    }
}
