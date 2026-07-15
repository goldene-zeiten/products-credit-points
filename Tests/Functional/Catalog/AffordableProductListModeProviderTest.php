<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\Catalog;

use GoldeneZeiten\Products\Core\Catalog\ProductListModeRegistry;
use GoldeneZeiten\Products\Core\Domain\Dto\Catalog\ProductListContext;
use GoldeneZeiten\Products\Core\Domain\Model\Product;
use GoldeneZeiten\Products\CreditPoints\Catalog\AffordableProductListModeProvider;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * The affordable listing reads the per-product point value from the column this extension owns and orders
 * cheapest-first, so a customer sees what their balance reaches before what only nearly reaches it.
 */
final class AffordableProductListModeProviderTest extends AbstractFunctionalTestCase
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
    public function affordableProductsAreReturnedCheapestFirst(): void
    {
        // Frontend user 1 has a balance of 70; both products (10 and 5 points) are affordable.
        $products = $this->subject()->findProducts(new ProductListContext($this->requestFor(1)));

        $titles = array_map(static fn(Product $product): string => $product->getTitle(), $products);
        $this->assertSame(['Product 2', 'Product 1'], $titles);
    }

    #[Test]
    public function aBalanceBelowTheCheapestProductReturnsNothing(): void
    {
        // Frontend user 3's ledger sums to 20, but with the products priced at 5 and 10 both remain
        // affordable; a guest with no balance is the "reaches nothing" case instead.
        $this->assertSame([], $this->subject()->findProducts(new ProductListContext($this->requestFor(0))));
    }

    #[Test]
    public function nothingIsListedWhenTheProgrammeIsDisabled(): void
    {
        $products = $this->subject()->findProducts(new ProductListContext($this->requestFor(1, false)));
        $this->assertSame([], $products);
    }

    #[Test]
    public function theModeRegistersWithTheCoreListModeRegistry(): void
    {
        // The core registry discovers the provider through its tagged-service contract, so installing this
        // extension is all it takes for the "affordable" listing to become selectable.
        $this->assertTrue($this->get(ProductListModeRegistry::class)->has('affordable'));
    }

    private function subject(): AffordableProductListModeProvider
    {
        return $this->get(AffordableProductListModeProvider::class);
    }

    private function requestFor(int $frontendUserUid, bool $enabled = true): ServerRequestInterface
    {
        $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
        $frontendUser->initializeUserSessionManager();
        if ($frontendUserUid > 0) {
            $frontendUser->user = ['uid' => $frontendUserUid];
        }
        $site = new Site('products', 1, ['settings' => ['products' => [
            'creditPoints' => ['enabled' => $enabled],
        ]]]);

        return (new ServerRequest('http://localhost/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('frontend.user', $frontendUser)
            ->withAttribute('site', $site);
    }
}
