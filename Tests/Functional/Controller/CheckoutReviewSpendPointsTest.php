<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\Controller;

use GoldeneZeiten\Products\Core\Domain\Dto\Address;
use GoldeneZeiten\Products\Testing\AbstractFrontendTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Session\SessionManager;
use TYPO3\CMS\Core\Session\UserSession;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * The review page shows the total the order will be placed with, so the credit points a customer selects
 * on it change that total before the order is placed.
 */
final class CheckoutReviewSpendPointsTest extends AbstractFrontendTestCase
{
    private const SESSION_ID = 'dddddddddddddddddddddddddddddddd';

    protected array $testExtensionsToLoad = [
        'goldene-zeiten/frontend-test',
        'goldene-zeiten/products-credit-points',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../../../products-core/Tests/Functional/Controller/Fixtures/checkout_content.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/review_with_points.csv');
        $this->writeSiteConfiguration(
            'products',
            $this->buildSiteConfiguration(1, additionalRootConfiguration: [
                'dependencies' => $this->siteSetDependencies(),
                'settings' => [
                    'products' => [
                        'creditPoints' => [
                            'enabled' => true,
                        ],
                    ],
                ],
            ]),
            [$this->buildDefaultLanguageConfiguration('en', '/')]
        );
        $this->get(SessionManager::class)->getSessionBackend('FE')->set(self::SESSION_ID, [
            'ses_iplock' => '[DISABLED]',
            'ses_userid' => 7,
            'ses_data' => serialize([
                'tx_products_basket' => '{"items":[{"productUid":1,"articleUid":null,"quantity":1}]}',
                'tx_products_checkout_address' => serialize(new Address(
                    email: 'points@example.com',
                    firstName: 'Jane',
                    lastName: 'Doe',
                    street: 'Main Street 1',
                    zip: '10115',
                    city: 'Berlin',
                    country: 'DE'
                )),
                'tx_products_checkout_payment' => 'invoice',
            ]),
        ]);
    }

    protected function siteSetDependencies(): array
    {
        return [
            ...parent::siteSetDependencies(),
            'goldene-zeiten/products-credit-points',
        ];
    }

    #[Test]
    public function theReviewWithoutSelectedPointsShowsTheFullTotal(): void
    {
        $body = (string)$this->executeFrontendSubRequest($this->reviewRequest(null))->getBody();

        $this->assertMatchesRegularExpression('#order-summary-total.*?<strong>€100\.00</strong>#s', $body);
        $this->assertStringContainsString('name="spendPoints"', $body);
    }

    #[Test]
    public function thePointsSelectedOnTheReviewReduceTheShownTotal(): void
    {
        $body = (string)$this->executeFrontendSubRequest($this->reviewRequest('30'))->getBody();

        $this->assertMatchesRegularExpression('#order-summary-total.*?<strong>€97\.00</strong>#s', $body);
    }

    /**
     * The order is placed with the points the review applied, not with whatever was typed afterwards.
     */
    #[Test]
    public function thePlacedOrderCarriesTheAppliedPointsInAHiddenField(): void
    {
        $body = (string)$this->executeFrontendSubRequest($this->reviewRequest('30'))->getBody();

        $this->assertMatchesRegularExpression('#<input type="hidden" name="spendPoints" value="30"#', $body);
    }

    private function reviewRequest(?string $spendPoints): InternalRequest
    {
        $cHash = $this->get(CacheHashCalculator::class)->generateForParameters('&id=2&tx_productscore_checkout[action]=review');
        $request = (new InternalRequest('http://localhost/shop'))
            ->withQueryParameters([
                'tx_productscore_checkout[action]' => 'review',
                'cHash' => $cHash,
            ])
            ->withCookieParams(['fe_typo_user' => UserSession::createFromRecord(self::SESSION_ID, [])->getJwt()]);
        if ($spendPoints === null) {
            return $request;
        }
        $parsedBody = ['spendPoints' => $spendPoints];

        return $request
            ->withMethod('POST')
            ->withParsedBody($parsedBody)
            ->withBody($this->get(StreamFactoryInterface::class)->createStream(http_build_query($parsedBody)));
    }
}
