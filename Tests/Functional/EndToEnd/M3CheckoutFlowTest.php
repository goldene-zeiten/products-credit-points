<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\EndToEnd;

use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Core\Domain\Dto\Checkout\CheckoutChoices;
use GoldeneZeiten\Products\Core\Domain\Model\Product;
use GoldeneZeiten\Products\Core\Domain\Repository\ProductRepository;
use GoldeneZeiten\Products\Core\Service\Basket\BasketService;
use GoldeneZeiten\Products\Core\Service\FrontendUserResolver;
use GoldeneZeiten\Products\Core\Service\Order\OrderPlacementService;
use GoldeneZeiten\Products\CreditPoints\Service\Exception\InsufficientCreditPointsException;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use GoldeneZeiten\Products\Voucher\Discount\VoucherCheckoutState;
use GoldeneZeiten\Products\Voucher\Domain\Repository\VoucherRedemptionRepository;
use GoldeneZeiten\Products\Voucher\Domain\Repository\VoucherRepository;
use GoldeneZeiten\Products\Voucher\Service\VoucherService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

final class M3CheckoutFlowTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-voucher',
        'goldene-zeiten/products-credit-points',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/M3CheckoutFlowTest/m3_end_to_end.csv');
    }

    #[Test]
    public function identifiedCustomerReplacesVoucherAndSpendsPartialPoints(): void
    {
        $basketService = $this->get(BasketService::class);
        $orderPlacementService = $this->get(OrderPlacementService::class);
        $mainProduct = $this->mainProduct();
        $request = $this->requestFor(7);
        $basketService->add($request, $mainProduct->getUid() ?? 0, null, 1);

        $relatedTitles = array_map(
            static fn(Product $related): string => $related->getTitle(),
            $mainProduct->getRelatedProducts()->toArray()
        );
        $this->assertSame(['Related Product'], $relatedTitles);

        $this->applyVoucher($basketService, $request, 'COMBO1');
        $voucherState = $this->get(VoucherCheckoutState::class);
        $this->assertSame(['COMBO1'], $voucherState->getCodes($request));

        $this->applyVoucher($basketService, $request, 'SOLO');
        $this->assertSame(['SOLO'], $voucherState->getCodes($request), 'A non-combinable voucher must replace, not join, an existing code.');

        $order = $orderPlacementService->place($request->withParsedBody(['spendPoints' => 30]), $this->address(), 'invoice', new CheckoutChoices(termsAccepted: true))->getOrder();

        $this->assertSame(9200, $order->getTotalGross()->getCents());
        $this->assertSame(800, $order->getDiscountTotal()->getCents());
        $this->assertSame(['SOLO'], $this->redeemedCodesFor($order->getUid() ?? 0));

        $voucher = $this->get(VoucherRepository::class)->findOneByCode('SOLO');
        $this->assertNotNull($voucher);
        $this->assertSame(1, $this->get(VoucherRedemptionRepository::class)->countFor($voucher));

        $this->assertCSVDataSet(__DIR__ . '/Fixtures/Result/m3_ledger_partial_points_redemption.csv');

    }

    #[Test]
    public function guestCheckoutIsRejectedForPointsButStillCompletesForTheVoucher(): void
    {
        $basketService = $this->get(BasketService::class);
        $orderPlacementService = $this->get(OrderPlacementService::class);
        $request = $this->requestFor(0);
        $basketService->add($request, $this->mainProduct()->getUid() ?? 0, null, 1);
        $this->applyVoucher($basketService, $request, 'COMBO1');

        try {
            $orderPlacementService->place($request->withParsedBody(['spendPoints' => 10]), $this->address(), 'invoice', new CheckoutChoices(termsAccepted: true));
            $this->fail('Expected InsufficientCreditPointsException was not thrown for a guest requesting points.');
        } catch (InsufficientCreditPointsException) {
            // expected: guests always have a zero balance
        }
        $this->assertCSVDataSet(__DIR__ . '/Fixtures/Result/m3_no_orders_after_rejected_points_request.csv');

        $order = $orderPlacementService->place($request, $this->address(), 'invoice', new CheckoutChoices(termsAccepted: true))->getOrder();

        $this->assertSame(9000, $order->getTotalGross()->getCents());
        $this->assertSame(1000, $order->getDiscountTotal()->getCents());
        $this->assertSame(['COMBO1'], $this->redeemedCodesFor($order->getUid() ?? 0));
        $this->assertCSVDataSet(__DIR__ . '/Fixtures/Result/m3_ledger_untouched_by_guest.csv');
    }

    private function mainProduct(): Product
    {
        $product = $this->get(ProductRepository::class)->findByUid(1);
        $this->assertInstanceOf(Product::class, $product);
        return $product;
    }

    private function applyVoucher(BasketService $basketService, ServerRequestInterface $request, string $code): void
    {
        $voucherService = $this->get(VoucherService::class);
        $voucherState = $this->get(VoucherCheckoutState::class);
        $frontendUser = $this->get(FrontendUserResolver::class)->getUid($request);
        $basketGoodsTotal = $basketService->getBasketViewModel($request)->getTotalGross();

        $newVoucher = $voucherService->resolve($code, $basketGoodsTotal, $frontendUser);
        $existingCodes = $voucherState->getCodes($request);
        $existingVouchers = $voucherService->buildDiscountSummary($existingCodes, $basketGoodsTotal, $frontendUser)->getAppliedVouchers();
        if (!$voucherService->canCoexist($existingVouchers, $newVoucher)) {
            $voucherState->clearCodes($request);
        }
        $voucherState->addCode($request, $newVoucher->getCode());
    }

    private function requestFor(int $frontendUserUid): ServerRequestInterface
    {
        $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
        $frontendUser->initializeUserSessionManager();
        if ($frontendUserUid > 0) {
            $frontendUser->user = ['uid' => $frontendUserUid];
        }
        $site = new Site('products', 1, ['settings' => ['products' => [
            'creditPoints' => ['enabled' => true],
        ]]]);
        return (new ServerRequest('http://localhost/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('frontend.user', $frontendUser)
            ->withAttribute('site', $site);
    }

    private function address(): Address
    {
        return new Address(email: 'buyer@example.com', country: 'DE');
    }

    /**
     * @return string[]
     */
    private function redeemedCodesFor(int $orderUid): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_products_domain_model_voucherredemption');
        $queryBuilder->getRestrictions()->removeAll();
        $codes = $queryBuilder->select('voucher_code')->from('tx_products_domain_model_voucherredemption')
            ->where($queryBuilder->expr()->eq('order_uid', $queryBuilder->createNamedParameter($orderUid, Connection::PARAM_INT)))
            ->executeQuery()->fetchFirstColumn();

        return array_map('strval', $codes);
    }
}
