<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\Service\Order;

use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Core\Domain\Dto\Checkout\CheckoutSelections;
use GoldeneZeiten\Products\Core\Domain\Model\Order;
use GoldeneZeiten\Products\Core\Payment\PaymentMethodRegistry;
use GoldeneZeiten\Products\Core\Service\Basket\BasketService;
use GoldeneZeiten\Products\Core\Service\Order\OrderCreationService;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use GoldeneZeiten\Products\Voucher\Discount\VoucherCheckoutState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Credit points pay for goods, and are earned on what the goods actually cost the customer: what a
 * voucher or a redemption took off the goods is neither spendable nor earning, and a waived shipping
 * cost is not taken off the goods at all.
 */
final class LoyaltyGoodsBaseTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-credit-points',
        'goldene-zeiten/products-voucher',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/LoyaltyGoodsBaseTest/goods_base.csv');
    }

    #[Test]
    public function pointsMayPayForAllGoodsWhenTheShippingIsWaived(): void
    {
        // 10.00 goods + 5.00 shipping, waived; 100 points at 0.10 cover the 10.00 goods in full.
        $order = $this->place(1, ['FREESHIP'], 100, []);

        $this->assertSame(0, $order->getTotalGross()->getCents());
        $this->assertSame(1500, $order->getDiscountTotal()->getCents());
        $this->assertSame(-100, $this->ledgerPoints('redeem'));
    }

    /**
     * @param string[] $voucherCodes
     * @param array<string, mixed> $earningSettings
     */
    #[Test]
    #[DataProvider('earningDataProvider')]
    public function pointsAreEarnedOnWhatTheGoodsCostAfterReductions(array $voucherCodes, int $spendPoints, array $earningSettings, int $expectedPoints): void
    {
        $this->place(2, $voucherCodes, $spendPoints, $earningSettings);

        $this->assertSame($expectedPoints, $this->ledgerPoints('earn'));
    }

    public static function earningDataProvider(): \Generator
    {
        // One point per euro paid for the goods.
        $factor = [
            'earningMode' => 'autoPriceFactor',
            'priceFactor' => 1.0,
        ];
        yield 'the full price earns in full' => [
            'voucherCodes' => [],
            'spendPoints' => 0,
            'earningSettings' => $factor,
            'expectedPoints' => 100,
        ];
        yield 'a voucher lowers what is earned' => [
            'voucherCodes' => ['TENPCT'],
            'spendPoints' => 0,
            'earningSettings' => $factor,
            'expectedPoints' => 90,
        ];
        yield 'redeemed points lower what is earned' => [
            'voucherCodes' => [],
            'spendPoints' => 50,
            'earningSettings' => $factor,
            'expectedPoints' => 95,
        ];
        yield 'a voucher and redeemed points together' => [
            'voucherCodes' => ['TENPCT'],
            'spendPoints' => 100,
            'earningSettings' => $factor,
            'expectedPoints' => 80,
        ];
    }

    /**
     * @param string[] $voucherCodes
     * @param array<string, mixed> $earningSettings
     */
    private function place(int $productUid, array $voucherCodes, int $spendPoints, array $earningSettings): Order
    {
        $request = $this->request($spendPoints, $earningSettings);
        $basketService = $this->get(BasketService::class);
        $basketService->add($request, $productUid, null, 1);
        foreach ($voucherCodes as $voucherCode) {
            $this->get(VoucherCheckoutState::class)->addCode($request, $voucherCode);
        }

        return $this->get(OrderCreationService::class)->create(
            $request,
            $basketService->getBasketViewModel($request, 'DE'),
            new CheckoutSelections('tablerate:1'),
            new Address(email: 'buyer@example.com', country: 'DE'),
            $this->get(PaymentMethodRegistry::class)->get('invoice')
        );
    }

    /**
     * @param array<string, mixed> $earningSettings
     */
    private function request(int $spendPoints, array $earningSettings): ServerRequestInterface
    {
        $site = new Site('products', 1, [
            'settings' => [
                'products' => [
                    'shipping' => [
                        'enabled' => true,
                    ],
                    'creditPoints' => [
                        'enabled' => true,
                        'moneyPerPoint' => '0.10',
                    ] + $earningSettings,
                ],
            ],
        ]);
        $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
        $frontendUser->initializeUserSessionManager();
        $frontendUser->user = ['uid' => 5];
        return (new ServerRequest('http://localhost/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('site', $site)
            ->withAttribute('frontend.user', $frontendUser)
            ->withParsedBody(['spendPoints' => $spendPoints]);
    }

    private function ledgerPoints(string $type): int
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_products_domain_model_creditpointstransaction');
        $points = $queryBuilder
            ->selectLiteral('SUM(' . $queryBuilder->quoteIdentifier('points') . ')')
            ->from('tx_products_domain_model_creditpointstransaction')
            ->where(
                $queryBuilder->expr()->eq('type', $queryBuilder->createNamedParameter($type)),
                $queryBuilder->expr()->gt('order_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
        return (int)$points;
    }
}
