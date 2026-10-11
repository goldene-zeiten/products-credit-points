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
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Every price reduction lowers net and VAT at the rate it reduces, and every charge carries the VAT of the
 * rate it is taxed at - so whatever an order combines, its net and VAT add up to its gross.
 *
 * The basket mixes 19% and 7% goods (one with a deposit), ships at 19%, and stacks a 10% voucher waiving
 * the shipping, redeemed credit points, a handling fee and a payment fee.
 */
final class OrderTotalsInvariantTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-credit-points',
        'goldene-zeiten/products-voucher',
        'goldene-zeiten/products-payment-fixture',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/OrderTotalsInvariantTest/mixed_rate_order.csv');
    }

    #[Test]
    public function netAndTaxAddUpToTheGrossTotal(): void
    {
        $order = $this->placeMixedOrder();

        $this->assertSame($order->getTotalGross()->getCents(), $order->getTotalNet()->getCents() + $order->getTotalTax()->getCents());
    }

    #[Test]
    public function everyReductionAndChargeMovesTheVatOfItsRate(): void
    {
        $order = $this->placeMixedOrder();

        // goods 140.40 + shipping 5.95 - free shipping 5.95 + handling 2.38 + payment fee 2.50 + deposit 0.50
        // - voucher 14.04 - points 5.00
        $this->assertSame(12674, $order->getTotalGross()->getCents());
        $this->assertSame(1844, $order->getTotalTax()->getCents());
        $this->assertSame(10830, $order->getTotalNet()->getCents());
        $this->assertSame(['19' => 1720, '7' => 124], $order->getTaxBreakdown());
    }

    #[Test]
    public function theOrderRecordsEveryChargeAndTheDiscountTotal(): void
    {
        $order = $this->placeMixedOrder();

        $this->assertSame(595, $order->getShippingTotal()->getCents());
        $this->assertSame(238, $order->getHandlingFeeTotal()->getCents());
        $this->assertSame(250, $order->getPaymentFeeTotal()->getCents());
        $this->assertSame(50, $order->getDepositTotal()->getCents());
        $this->assertSame(2499, $order->getDiscountTotal()->getCents());
    }

    #[Test]
    public function theReviewQuoteShowsWhatTheOrderCharges(): void
    {
        $request = $this->request();
        $basketService = $this->get(BasketService::class);
        $basketService->add($request, 1, null, 1);
        $basketService->add($request, 2, null, 2);
        $this->get(VoucherCheckoutState::class)->addCode($request, 'TENOFF');
        $subject = $this->get(OrderCreationService::class);

        $totals = $subject->quote(
            $request,
            $basketService->getBasketViewModel($request, 'DE'),
            new CheckoutSelections('tablerate:1'),
            new Address(email: 'buyer@example.com', country: 'DE'),
            $this->get(PaymentMethodRegistry::class)->get('fixture-surcharge')
        );

        $this->assertSame(12674, $totals->getTotalGross()->getCents());
        $this->assertSame(1844, $totals->getTotalTax()->getCents());
        $this->assertSame(['19' => 1720, '7' => 124], $totals->getTaxBreakdown());
        $this->assertSame(14040, $totals->getItemsTotalGross()->getCents());
        $this->assertSame(0, $totals->getRoundingDifference()->getCents());
    }

    private function placeMixedOrder(): Order
    {
        $request = $this->request();
        $basketService = $this->get(BasketService::class);
        $basketService->add($request, 1, null, 1);
        $basketService->add($request, 2, null, 2);
        $this->get(VoucherCheckoutState::class)->addCode($request, 'TENOFF');

        return $this->get(OrderCreationService::class)->create(
            $request,
            $basketService->getBasketViewModel($request, 'DE'),
            new CheckoutSelections('tablerate:1'),
            new Address(email: 'buyer@example.com', country: 'DE'),
            $this->get(PaymentMethodRegistry::class)->get('fixture-surcharge')
        );
    }

    private function request(): ServerRequestInterface
    {
        $site = new Site('products', 1, [
            'settings' => [
                'products' => [
                    'shipping' => [
                        'enabled' => true,
                    ],
                    'handling' => [
                        'enabled' => true,
                    ],
                    'creditPoints' => [
                        'enabled' => true,
                        'moneyPerPoint' => '0.10',
                    ],
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
            ->withParsedBody(['spendPoints' => 50]);
    }
}
