<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Unit\Configuration;

use GoldeneZeiten\Products\CreditPoints\Configuration\CreditPointsConfigurationFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * earningTiers is deliberately not covered here - a bare `new Site(...)` can't resolve array-valued
 * settings; see {@see CreditPointsConfigurationFactoryEarningTiersTest}.
 */
final class CreditPointsConfigurationFactoryTest extends UnitTestCase
{
    #[Test]
    public function settingsAreReadFromTheSite(): void
    {
        $site = new Site('products', 1, ['settings' => ['products' => [
            'creditPoints' => [
                'enabled' => true,
                'moneyPerPoint' => '0.25',
                'earningMode' => 'basketTiered',
                'priceFactor' => 2.0,
            ],
        ]]]);
        $request = (new ServerRequest('http://localhost/'))->withAttribute('site', $site);

        $configuration = $this->subject()->create($request);

        $this->assertTrue($configuration->isEnabled());
        $this->assertSame(25, $configuration->getMoneyPerPoint()->getCents());
        $this->assertSame('basketTiered', $configuration->getEarningMode());
        $this->assertSame(2.0, $configuration->getPriceFactor());
    }

    #[Test]
    public function settingsDefaultToDisabledWithoutASite(): void
    {
        $configuration = $this->subject()->create(new ServerRequest('http://localhost/'));

        $this->assertFalse($configuration->isEnabled());
        $this->assertSame(10, $configuration->getMoneyPerPoint()->getCents());
        $this->assertSame('perProduct', $configuration->getEarningMode());
        $this->assertSame([], $configuration->getEarningTiers());
        $this->assertSame(0.0, $configuration->getPriceFactor());
    }

    private function subject(): CreditPointsConfigurationFactory
    {
        return new CreditPointsConfigurationFactory();
    }
}
