<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\Service;

use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewItem;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewModel;
use GoldeneZeiten\Products\Core\Domain\Model\Product;
use GoldeneZeiten\Products\Core\Domain\Repository\ProductRepository;
use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\CreditPoints\Configuration\CreditPointsConfiguration;
use GoldeneZeiten\Products\CreditPoints\Domain\Dto\CreditPointsEarningTier;
use GoldeneZeiten\Products\CreditPoints\Service\CreditPointsService;
use GoldeneZeiten\Products\CreditPoints\Service\Exception\InsufficientCreditPointsException;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class CreditPointsServiceTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-credit-points',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/credit_points.csv');
    }

    #[Test]
    public function balanceIsTheSumOfLedgerEntries(): void
    {
        $subject = $this->subject();
        $this->assertSame(70, $subject->getBalance(1));
        $this->assertSame(50, $subject->getBalance(2));
    }

    #[Test]
    public function balanceIsZeroForAFrontendUserWithNoLedgerEntries(): void
    {
        $subject = $this->subject();
        $this->assertSame(0, $subject->getBalance(999));
    }

    #[Test]
    public function guestsAlwaysHaveAZeroBalanceWithoutQuerying(): void
    {
        $subject = $this->subject();
        $this->assertSame(0, $subject->getBalance(0));
    }

    #[Test]
    public function manualAdjustmentRowsCountTowardTheBalance(): void
    {
        $subject = $this->subject();
        $this->assertSame(20, $subject->getBalance(3));
    }

    #[Test]
    public function earnedPointsSumTheProductRateAcrossBasketLinesAndQuantities(): void
    {
        $subject = $this->subject();
        $this->assertSame(10 * 2 + 5 * 3, $subject->calculateEarnedPoints($this->basketViewModel(), $this->configuration()));
    }

    #[Test]
    public function perProductModeIgnoresConfiguredTiers(): void
    {
        $subject = $this->subject();
        $configuration = $this->configuration(earningMode: 'perProduct', earningTiers: ['0.00:999']);

        $this->assertSame(10 * 2 + 5 * 3, $subject->calculateEarnedPoints($this->basketViewModel(), $configuration));
    }

    /**
     * @param string[] $earningTiers
     */
    #[Test]
    #[DataProvider('basketTieredModeProvider')]
    public function basketTieredModeAwardsPointsAccordingToTheQualifyingTier(array $earningTiers, string $basketTotal, int $expectedPoints): void
    {
        $subject = $this->subject();
        $configuration = $this->configuration(earningMode: 'basketTiered', earningTiers: $earningTiers);

        $this->assertSame($expectedPoints, $subject->calculateEarnedPoints($this->basketWithTotal($basketTotal), $configuration));
    }

    public static function basketTieredModeProvider(): \Generator
    {
        yield 'awards the highest qualifying tier below the top' => ['earningTiers' => ['50.00:10', '100.00:25'], 'basketTotal' => '75.00', 'expectedPoints' => 10];
        yield 'awards the highest qualifying tier at the top' => ['earningTiers' => ['50.00:10', '100.00:25'], 'basketTotal' => '150.00', 'expectedPoints' => 25];
        yield 'awards no points below the lowest tier' => ['earningTiers' => ['50.00:10'], 'basketTotal' => '49.99', 'expectedPoints' => 0];
        yield 'exactly at the threshold qualifies' => ['earningTiers' => ['50.00:10'], 'basketTotal' => '50.00', 'expectedPoints' => 10];
    }

    #[Test]
    public function autoPriceFactorModeUsesTheExplicitRateWhenPresent(): void
    {
        $subject = $this->subject();
        $configuration = $this->configuration(earningMode: 'autoPriceFactor', priceFactor: 1.0);

        $this->assertSame(10 * 2 + 5 * 3, $subject->calculateEarnedPoints($this->basketViewModel(), $configuration));
    }

    #[Test]
    public function autoPriceFactorModeConvertsPriceToPointsForUnratedLines(): void
    {
        $subject = $this->subject();
        $configuration = $this->configuration(earningMode: 'autoPriceFactor', priceFactor: 2.0);
        // A product with no row of its own carries no explicit points, so the line falls to the price factor.
        $unratedProduct = new Product();
        $unitPrice = Money::fromDecimalString('10.00');
        $lineTotal = $unitPrice->multiply(3);
        $item = new BasketViewItem($unratedProduct, null, 3, $unitPrice, $unitPrice, 0.0, $lineTotal, $lineTotal, Money::fromCents(0));
        $basket = new BasketViewModel([$item], $lineTotal, $lineTotal, Money::fromCents(0), 'EUR');

        // lineTotalGross 30.00 * priceFactor 2.0 = 60 points
        $this->assertSame(60, $subject->calculateEarnedPoints($basket, $configuration));
    }

    #[Test]
    #[DataProvider('redeemClampingProvider')]
    public function redeemClampsToWhicheverLimitIsLower(int $requestedPoints, string $basketTotal, int $expectedPoints, int $expectedDiscountCents): void
    {
        $subject = $this->subject();
        $redemption = $subject->redeem(1, $requestedPoints, Money::fromDecimalString($basketTotal), $this->configuration());

        $this->assertSame($expectedPoints, $redemption->getPoints());
        $this->assertSame($expectedDiscountCents, $redemption->getDiscountAmount()->getCents());
    }

    public static function redeemClampingProvider(): \Generator
    {
        yield 'clamps to balance when more is requested than available' => ['requestedPoints' => 1000, 'basketTotal' => '1000.00', 'expectedPoints' => 70, 'expectedDiscountCents' => 700];
        yield 'clamps to what the basket can absorb' => ['requestedPoints' => 70, 'basketTotal' => '3.00', 'expectedPoints' => 30, 'expectedDiscountCents' => 300];
    }

    #[Test]
    public function guestsCanNeverRedeemPoints(): void
    {
        $subject = $this->subject();
        $this->assertTrue($subject->redeem(0, 100, Money::fromDecimalString('1000.00'), $this->configuration())->isEmpty());
    }

    #[Test]
    public function redeemIsANoOpWhenTheFeatureIsDisabled(): void
    {
        $subject = $this->subject();
        $configuration = $this->configuration(enabled: false);

        $this->assertTrue($subject->redeem(1, 10, Money::fromDecimalString('100.00'), $configuration)->isEmpty());
    }

    #[Test]
    public function assertSpendableThrowsWhenRequestingMoreThanTheBalance(): void
    {
        $subject = $this->subject();
        $this->expectException(InsufficientCreditPointsException::class);
        $this->expectExceptionCode(1783430000);

        $subject->assertSpendable(1, 71);
    }

    #[Test]
    public function assertSpendableAllowsRequestsWithinBalance(): void
    {
        $subject = $this->subject();
        $subject->assertSpendable(1, 70);
        $this->addToAssertionCount(1);
    }

    private function subject(): CreditPointsService
    {
        return $this->get(CreditPointsService::class);
    }

    /**
     * @param string[] $earningTiers
     */
    private function configuration(bool $enabled = true, string $moneyPerPoint = '0.10', string $earningMode = 'perProduct', array $earningTiers = [], float $priceFactor = 0.0): CreditPointsConfiguration
    {
        return new CreditPointsConfiguration($enabled, Money::fromDecimalString($moneyPerPoint), $earningMode, $this->parseEarningTiers($earningTiers), $priceFactor);
    }

    /**
     * @param string[] $rawTiers
     * @return CreditPointsEarningTier[]
     */
    private function parseEarningTiers(array $rawTiers): array
    {
        $tiers = [];
        foreach ($rawTiers as $entry) {
            [$threshold, $points] = explode(':', $entry, 2);
            $tiers[] = new CreditPointsEarningTier(Money::fromDecimalString($threshold), (int)$points);
        }
        return $tiers;
    }

    private function basketViewModel(): BasketViewModel
    {
        $productRepository = $this->get(ProductRepository::class);
        $product1 = $productRepository->findByUid(1);
        $product2 = $productRepository->findByUid(2);
        $this->assertInstanceOf(Product::class, $product1);
        $this->assertInstanceOf(Product::class, $product2);

        $price = Money::fromDecimalString('10.00');
        $noTax = Money::fromCents(0);
        $items = [
            new BasketViewItem($product1, null, 2, $price, $price, 0.0, $price, $price, $noTax),
            new BasketViewItem($product2, null, 3, $price, $price, 0.0, $price, $price, $noTax),
        ];
        return new BasketViewModel($items, $price, $price, $noTax, 'EUR');
    }

    private function basketWithTotal(string $total): BasketViewModel
    {
        $money = Money::fromDecimalString($total);
        $noTax = Money::fromCents(0);
        return new BasketViewModel([], $money, $money, $noTax, 'EUR');
    }
}
