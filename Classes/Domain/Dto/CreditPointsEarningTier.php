<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Domain\Dto;

use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class CreditPointsEarningTier
{
    public function __construct(
        private Money $threshold,
        private int $points
    ) {}

    public function getThreshold(): Money
    {
        return $this->threshold;
    }

    public function getPoints(): int
    {
        return $this->points;
    }
}
