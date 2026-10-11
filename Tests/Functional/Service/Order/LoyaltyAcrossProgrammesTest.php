<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\Service\Order;

use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Core\Domain\Dto\Checkout\CheckoutSelections;
use GoldeneZeiten\Products\Core\Payment\PaymentMethodRegistry;
use GoldeneZeiten\Products\Core\Service\Basket\BasketService;
use GoldeneZeiten\Products\Core\Service\Order\OrderCreationService;
use GoldeneZeiten\Products\LoyaltyFixture\FixtureLoyaltyProvider;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Two loyalty programmes redeem against one order: the second may only spend on what the first left of
 * the goods, so together they never pay out more than the goods cost.
 */
final class LoyaltyAcrossProgrammesTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-credit-points',
        'goldene-zeiten/products-loyalty-fixture',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/LoyaltyAcrossProgrammesTest/goods.csv');
        FixtureLoyaltyProvider::reset();
    }

    protected function tearDown(): void
    {
        FixtureLoyaltyProvider::reset();
        parent::tearDown();
    }

    #[Test]
    public function redemptionsOfAllProgrammesTogetherStopAtTheGoodsTotal(): void
    {
        // Both programmes are asked for 1000 points: worth 10.00 in the fixture programme and 100.00 in
        // credit points, against 10.00 of goods.
        FixtureLoyaltyProvider::$balances[5] = 5000;
        $site = new Site('products', 1, [
            'settings' => [
                'products' => [
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
        $request = (new ServerRequest('http://localhost/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('site', $site)
            ->withAttribute('frontend.user', $frontendUser)
            ->withParsedBody(['spendPoints' => 1000]);
        $basketService = $this->get(BasketService::class);
        $basketService->add($request, 1, null, 1);

        $order = $this->get(OrderCreationService::class)->create(
            $request,
            $basketService->getBasketViewModel($request, 'DE'),
            new CheckoutSelections(),
            new Address(email: 'buyer@example.com', country: 'DE'),
            $this->get(PaymentMethodRegistry::class)->get('invoice')
        );

        $this->assertSame(0, $order->getTotalGross()->getCents());
        $this->assertSame(1000, $order->getDiscountTotal()->getCents());
    }
}
