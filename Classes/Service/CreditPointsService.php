<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Service;

use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewItem;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewModel;
use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\CreditPoints\Configuration\CreditPointsConfiguration;
use GoldeneZeiten\Products\CreditPoints\Domain\Dto\CreditPointsRedemption;
use GoldeneZeiten\Products\CreditPoints\Service\Exception\InsufficientCreditPointsException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class CreditPointsService
{
    private const PRODUCT_TABLE = 'tx_products_domain_model_product';

    public function __construct(
        private readonly CreditPointsBalanceService $creditPointsBalanceService,
        private readonly ConnectionPool $connectionPool
    ) {}

    public function getBalance(int $frontendUser): int
    {
        return $this->creditPointsBalanceService->getBalance($frontendUser);
    }

    /**
     * Earning modes: per-product (default), tiered (highest-qualifying), or auto price factor.
     */
    public function calculateEarnedPoints(BasketViewModel $basket, CreditPointsConfiguration $configuration): int
    {
        return match ($configuration->getEarningMode()) {
            'basketTiered' => $this->calculateTieredPoints($basket->getTotalGross(), $configuration),
            'autoPriceFactor' => $this->calculateAutoPriceFactorPoints($basket, $configuration),
            default => $this->calculatePerProductPoints($basket),
        };
    }

    private function calculatePerProductPoints(BasketViewModel $basket): int
    {
        $points = 0;
        foreach ($basket->getItems() as $item) {
            $points += $this->productCreditPoints($item) * $item->getQuantity();
        }
        return $points;
    }

    private function calculateAutoPriceFactorPoints(BasketViewModel $basket, CreditPointsConfiguration $configuration): int
    {
        $priceFactor = $configuration->getPriceFactor();
        $points = 0;
        foreach ($basket->getItems() as $item) {
            $explicitPoints = $this->productCreditPoints($item);
            $points += $explicitPoints > 0
                ? $explicitPoints * $item->getQuantity()
                : (int)floor($item->getLineTotalGross()->getCents() / 100 * $priceFactor);
        }
        return $points;
    }

    /**
     * The per-unit points value is a column this extension owns on the product table, not a property of
     * the core Product model, so it is read straight off the row for the basket line's product.
     */
    private function productCreditPoints(BasketViewItem $item): int
    {
        $productUid = $item->getProduct()->getUid() ?? 0;
        if ($productUid === 0) {
            return 0;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::PRODUCT_TABLE);
        $points = $queryBuilder
            ->select('credit_points')
            ->from(self::PRODUCT_TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($productUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return $points === false ? 0 : (int)$points;
    }

    private function calculateTieredPoints(Money $basketTotal, CreditPointsConfiguration $configuration): int
    {
        $points = 0;
        $bestThresholdCents = -1;
        foreach ($configuration->getEarningTiers() as $tier) {
            $thresholdCents = $tier->getThreshold()->getCents();
            if ($basketTotal->getCents() >= $thresholdCents && $thresholdCents > $bestThresholdCents) {
                $bestThresholdCents = $thresholdCents;
                $points = $tier->getPoints();
            }
        }
        return $points;
    }

    public function calculateRedemptionValue(int $points, CreditPointsConfiguration $configuration): Money
    {
        return $configuration->getMoneyPerPoint()->multiply($points);
    }

    /**
     * @throws InsufficientCreditPointsException
     */
    public function assertSpendable(int $frontendUser, int $requestedPoints): void
    {
        $balance = $this->getBalance($frontendUser);
        if ($requestedPoints > $balance) {
            throw new InsufficientCreditPointsException(
                sprintf('Requested %d credit points but only %d are available.', $requestedPoints, $balance),
                1783430000
            );
        }
    }

    /**
     * Clamps to balance and basket capacity; guests (frontend_user 0) never redeem.
     */
    public function redeem(int $frontendUser, int $requestedPoints, Money $basketGoodsTotal, CreditPointsConfiguration $configuration): CreditPointsRedemption
    {
        $points = $this->clampRedeemablePoints($frontendUser, $requestedPoints, $basketGoodsTotal, $configuration);
        return new CreditPointsRedemption($points, $this->calculateRedemptionValue($points, $configuration));
    }

    private function clampRedeemablePoints(int $frontendUser, int $requestedPoints, Money $basketGoodsTotal, CreditPointsConfiguration $configuration): int
    {
        $moneyPerPointCents = $configuration->getMoneyPerPoint()->getCents();
        if (!$configuration->isEnabled() || $requestedPoints <= 0 || $frontendUser === 0 || $moneyPerPointCents <= 0) {
            return 0;
        }
        $maxByBasketValue = intdiv($basketGoodsTotal->getCents(), $moneyPerPointCents);
        return max(0, min($requestedPoints, $this->getBalance($frontendUser), $maxByBasketValue));
    }
}
