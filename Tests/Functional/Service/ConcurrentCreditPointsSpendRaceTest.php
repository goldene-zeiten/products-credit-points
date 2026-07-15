<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\Service;

use GoldeneZeiten\Products\CreditPoints\Service\CreditPointsBalanceService;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Atomic SQL UPDATE guards balance via {@see CreditPointsBalanceService::debitIfAffordable()}.
 */
final class ConcurrentCreditPointsSpendRaceTest extends AbstractFunctionalTestCase
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
    public function secondConcurrentSpendAgainstTheSameBalanceIsAtomicallyRejected(): void
    {
        $creditPointsBalanceService = $this->get(CreditPointsBalanceService::class);

        // User 1 balance: 70 (ledger), then adopted to balance table by ensureRowExists().
        $this->assertTrue($creditPointsBalanceService->debitIfAffordable(1, 60));
        $this->assertFalse($creditPointsBalanceService->debitIfAffordable(1, 60));

        $this->assertSame(10, $creditPointsBalanceService->getBalance(1));
    }
}
