<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\Service\Order;

use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewItem;
use GoldeneZeiten\Products\Core\Domain\Dto\BasketViewModel;
use GoldeneZeiten\Products\Core\Domain\Dto\Checkout\CheckoutSelections;
use GoldeneZeiten\Products\Core\Domain\Model\Product;
use GoldeneZeiten\Products\Core\Domain\Repository\ProductRepository;
use GoldeneZeiten\Products\Core\Domain\ValueObject\Money;
use GoldeneZeiten\Products\Core\Payment\PaymentMethodInterface;
use GoldeneZeiten\Products\Core\Payment\PaymentMethodRegistry;
use GoldeneZeiten\Products\Core\Service\Order\OrderCreationService;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use SBUERK\TYPO3\Testing\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

final class OrderCreationServiceCreditPointsTest extends AbstractFunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const LANGUAGE_PRESETS = [
        'en' => [
            'id' => 0,
            'title' => 'English',
            'locale' => 'en_US.UTF-8',
        ],
    ];

    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-credit-points',
        'goldene-zeiten/frontend-test',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/OrderCreationServiceCreditPointsTest/order_placement_with_credit_points.csv');
    }

    #[Test]
    public function identifiedCustomerEarnsAndRedeemsPointsOnPlacement(): void
    {
        $subject = $this->get(OrderCreationService::class);

        $order = $subject->create(
            $this->requestFor(enabled: true, frontendUserUid: 5, spendPoints: 20),
            $this->basketViewModel($this->product()),
            new CheckoutSelections(),
            $this->address(),
            $this->paymentMethod()
        );

        $this->assertCSVDataSet(__DIR__ . '/Fixtures/Result/credit_points_ledger_2_rows.csv');
        $this->assertSame(19800, $order->getTotalGross()->getCents());
        $this->assertSame(200, $order->getDiscountTotal()->getCents());
    }

    #[Test]
    public function guestOrdersNeverTouchTheLedgerEvenThoughTheProductEarnsPoints(): void
    {
        $subject = $this->get(OrderCreationService::class);

        $order = $subject->create(
            $this->requestFor(enabled: true, frontendUserUid: 0),
            $this->basketViewModel($this->product()),
            new CheckoutSelections(),
            $this->address(),
            $this->paymentMethod()
        );

        $this->assertCSVDataSet(__DIR__ . '/Fixtures/Result/credit_points_ledger_only_preexisting_row.csv');
    }

    #[Test]
    public function nothingIsRecordedOrDiscountedWhileTheFeatureIsDisabled(): void
    {
        $subject = $this->get(OrderCreationService::class);

        $order = $subject->create(
            $this->requestFor(enabled: false, frontendUserUid: 5, spendPoints: 20),
            $this->basketViewModel($this->product()),
            new CheckoutSelections(),
            $this->address(),
            $this->paymentMethod()
        );

        $this->assertCSVDataSet(__DIR__ . '/Fixtures/Result/credit_points_ledger_only_preexisting_row.csv');
        $this->assertSame(0, $order->getDiscountTotal()->getCents());
    }

    private function requestFor(bool $enabled, int $frontendUserUid, int $spendPoints = 0): ServerRequestInterface
    {
        $this->writeSiteConfiguration(
            'products',
            $this->buildSiteConfiguration(2, additionalRootConfiguration: [
                'dependencies' => ['goldene-zeiten/products-core', 'goldene-zeiten/frontend-test'],
                'settings' => [
                    'products' => [
                        'creditPoints' => ['enabled' => $enabled],
                    ],
                ],
            ]),
            [$this->buildDefaultLanguageConfiguration('en', '/')]
        );
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('products');

        $request = (new ServerRequest('http://localhost/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('site', $site)
            ->withParsedBody(['spendPoints' => $spendPoints]);
        if ($frontendUserUid === 0) {
            return $request;
        }
        $frontendUser = new FrontendUserAuthentication();
        $frontendUser->user = ['uid' => $frontendUserUid];
        return $request->withAttribute('frontend.user', $frontendUser);
    }

    private function product(): Product
    {
        $product = $this->get(ProductRepository::class)->findByUid(1);
        $this->assertInstanceOf(Product::class, $product);
        return $product;
    }

    private function basketViewModel(Product $product): BasketViewModel
    {
        $unitPrice = Money::fromDecimalString('100.00');
        $lineTotal = Money::fromDecimalString('200.00');
        $item = new BasketViewItem($product, null, 2, $unitPrice, $unitPrice, 0.0, $lineTotal, $lineTotal, Money::fromCents(0));
        return new BasketViewModel([$item], $lineTotal, $lineTotal, Money::fromCents(0), 'EUR');
    }

    private function address(): Address
    {
        return new Address(email: 'buyer@example.com', country: 'DE');
    }

    private function paymentMethod(): PaymentMethodInterface
    {
        return $this->get(PaymentMethodRegistry::class)->get('invoice');
    }
}
