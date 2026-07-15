<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Configuration;

use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\CreditPoints\Domain\Dto\CreditPointsEarningTier;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Site Settings read from request's site attribute (not via ConfigurationManagerInterface).
 */
final class CreditPointsConfigurationFactory
{
    public function create(ServerRequestInterface $request): CreditPointsConfiguration
    {
        $site = $request->getAttribute('site');
        $settings = $site instanceof Site ? $site->getSettings() : null;

        return new CreditPointsConfiguration(
            (bool)($settings?->get('products.creditPoints.enabled', false) ?? false),
            Money::fromDecimalString((string)($settings?->get('products.creditPoints.moneyPerPoint', '0.10') ?? '0.10')),
            (string)($settings?->get('products.creditPoints.earningMode', 'perProduct') ?? 'perProduct'),
            $this->parseEarningTiers((array)($settings?->get('products.creditPoints.earningTiers', []) ?? [])),
            (float)($settings?->get('products.creditPoints.priceFactor', 0.0) ?? 0.0)
        );
    }

    /**
     * @param array<int, mixed> $rawTiers
     * @return CreditPointsEarningTier[]
     */
    private function parseEarningTiers(array $rawTiers): array
    {
        $tiers = [];
        foreach ($rawTiers as $entry) {
            $parts = explode(':', (string)$entry, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $tiers[] = new CreditPointsEarningTier(Money::fromDecimalString(trim($parts[0])), (int)trim($parts[1]));
        }
        return $tiers;
    }
}
