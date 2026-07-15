<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\Configuration;

use GoldeneZeiten\Products\CreditPoints\Configuration\CreditPointsConfigurationFactory;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use SBUERK\TYPO3\Testing\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A `stringlist` setting needs a real Site via SiteFinder; `new Site(...)` bypasses typed Settings/Sets resolution.
 */
final class CreditPointsConfigurationFactoryEarningTiersTest extends AbstractFunctionalTestCase
{
    use SiteBasedTestTrait;
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-credit-points',
    ];

    protected const LANGUAGE_PRESETS = [
        'en' => [
            'id' => 0,
            'title' => 'English',
            'locale' => 'en_US.UTF-8',
        ],
    ];

    /**
     * @param string[] $tierStrings
     * @param array<int, array{threshold: int, points: int}> $expectedTiers
     */
    #[Test]
    #[DataProvider('earningTiersDataProvider')]
    public function earningTiersAreParsedFromTheStringlistSiteSetting(array $tierStrings, array $expectedTiers): void
    {
        $configuration = $this->get(CreditPointsConfigurationFactory::class)->create($this->requestWithEarningTiers($tierStrings));

        $tiers = $configuration->getEarningTiers();
        $this->assertCount(count($expectedTiers), $tiers);
        foreach ($expectedTiers as $index => $expectedTier) {
            $this->assertSame($expectedTier['threshold'], $tiers[$index]->getThreshold()->getCents());
            $this->assertSame($expectedTier['points'], $tiers[$index]->getPoints());
        }
    }

    public static function earningTiersDataProvider(): \Generator
    {
        yield 'earning tiers are parsed from the stringlist site setting' => [
            'tierStrings' => ['50.00:10', '100.00:25'],
            'expectedTiers' => [
                ['threshold' => 5000, 'points' => 10],
                ['threshold' => 10000, 'points' => 25],
            ],
        ];
        yield 'earning tiers default to an empty list without the setting' => [
            'tierStrings' => [],
            'expectedTiers' => [],
        ];
    }

    /**
     * @param string[] $earningTiers
     */
    private function requestWithEarningTiers(array $earningTiers): ServerRequestInterface
    {
        $this->writeSiteConfiguration(
            'products',
            $this->buildSiteConfiguration(1, additionalRootConfiguration: [
                'dependencies' => ['goldene-zeiten/products-core', 'goldene-zeiten/products-credit-points'],
                'settings' => [
                    'products' => [
                        'creditPoints' => ['earningTiers' => $earningTiers],
                    ],
                ],
            ]),
            [$this->buildDefaultLanguageConfiguration('en', '/')]
        );
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('products');

        return (new ServerRequest('http://localhost/'))->withAttribute('site', $site);
    }
}
