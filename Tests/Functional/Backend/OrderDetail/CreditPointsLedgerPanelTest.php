<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\Backend\OrderDetail;

use GoldeneZeiten\Products\CreditPoints\Backend\OrderDetail\CreditPointsLedgerPanel;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class CreditPointsLedgerPanelTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-credit-points',
    ];

    private function subject(): CreditPointsLedgerPanel
    {
        return $this->get(CreditPointsLedgerPanel::class);
    }

    #[Test]
    public function rendersTheLedgerRowsForAnOrder(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/credit_points_ledger.csv');

        $html = $this->subject()->renderForOrder(1);

        $this->assertNotNull($html);
        $this->assertStringContainsString('earn', $html);
        $this->assertStringContainsString('20', $html);
    }

    #[Test]
    public function rendersNothingForAnOrderWithoutLedgerEntries(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/credit_points_ledger.csv');

        $this->assertNull($this->subject()->renderForOrder(2));
    }
}
