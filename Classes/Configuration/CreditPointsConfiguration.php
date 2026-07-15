<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Configuration;

use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\CreditPoints\Domain\Dto\CreditPointsEarningTier;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class CreditPointsConfiguration
{
    /**
     * @param CreditPointsEarningTier[] $earningTiers
     */
    public function __construct(
        private bool $enabled,
        private Money $moneyPerPoint,
        private string $earningMode,
        private array $earningTiers,
        private float $priceFactor
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getMoneyPerPoint(): Money
    {
        return $this->moneyPerPoint;
    }

    public function getEarningMode(): string
    {
        return $this->earningMode;
    }

    /**
     * @return CreditPointsEarningTier[]
     */
    public function getEarningTiers(): array
    {
        return $this->earningTiers;
    }

    public function getPriceFactor(): float
    {
        return $this->priceFactor;
    }
}
