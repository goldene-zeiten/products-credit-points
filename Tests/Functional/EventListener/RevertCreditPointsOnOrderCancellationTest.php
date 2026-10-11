<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Tests\Functional\EventListener;

use GoldeneZeiten\Products\Core\Domain\Enum\OrderStatus;
use GoldeneZeiten\Products\Core\Domain\Model\Order;
use GoldeneZeiten\Products\Core\Domain\Repository\OrderRepository;
use GoldeneZeiten\Products\Core\Event\OrderStatusChangedEvent;
use GoldeneZeiten\Products\Core\Service\Order\OrderStatusManager;
use GoldeneZeiten\Products\CreditPoints\Service\CreditPointsService;
use GoldeneZeiten\Products\Testing\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A cancelled order neither costs the customer the points spent on it nor earns any.
 */
final class RevertCreditPointsOnOrderCancellationTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'goldene-zeiten/products-credit-points',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RevertCreditPointsOnOrderCancellationTest/cancellable_orders.csv');
    }

    #[Test]
    public function cancellingAnOrderRefundsSpentAndRevokesEarnedPoints(): void
    {
        $this->get(OrderStatusManager::class)->transition($this->order(1), OrderStatus::CANCELLED);

        $this->assertSame(100, $this->get(CreditPointsService::class)->getBalance(5));
        $this->assertSame(['refund' => 30, 'revoke' => -20], $this->reversals(1));
    }

    #[Test]
    public function earnedPointsAlreadySpentAreRevokedAsFarAsTheBalanceReaches(): void
    {
        $this->get(OrderStatusManager::class)->transition($this->order(2), OrderStatus::CANCELLED);

        $this->assertSame(0, $this->get(CreditPointsService::class)->getBalance(6));
        $this->assertSame(['revoke' => -5], $this->reversals(2));
    }

    #[Test]
    public function revertingTwiceBooksTheReversalOnce(): void
    {
        $eventDispatcher = $this->get(EventDispatcherInterface::class);

        $eventDispatcher->dispatch(new OrderStatusChangedEvent(1, OrderStatus::PENDING, OrderStatus::CANCELLED));
        $eventDispatcher->dispatch(new OrderStatusChangedEvent(1, OrderStatus::PENDING, OrderStatus::CANCELLED));

        $this->assertSame(100, $this->get(CreditPointsService::class)->getBalance(5));
        $this->assertSame(['refund' => 30, 'revoke' => -20], $this->reversals(1));
    }

    #[Test]
    public function aShippedOrderKeepsItsPoints(): void
    {
        $this->get(EventDispatcherInterface::class)->dispatch(new OrderStatusChangedEvent(1, OrderStatus::SHIPPED, OrderStatus::CANCELLED));

        $this->assertSame(90, $this->get(CreditPointsService::class)->getBalance(5));
        $this->assertSame([], $this->reversals(1));
    }

    private function order(int $uid): Order
    {
        $order = $this->get(OrderRepository::class)->findByUidIgnoringStoragePage($uid);
        $this->assertInstanceOf(Order::class, $order);
        return $order;
    }

    /**
     * @return array<string, int> points by reversal type
     */
    private function reversals(int $orderUid): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_products_domain_model_creditpointstransaction');
        $result = $queryBuilder->select('type', 'points')
            ->from('tx_products_domain_model_creditpointstransaction')
            ->where(
                $queryBuilder->expr()->eq('order_uid', $queryBuilder->createNamedParameter($orderUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('type', $queryBuilder->createNamedParameter(['refund', 'revoke'], Connection::PARAM_STR_ARRAY))
            )
            ->orderBy('uid')
            ->executeQuery();
        $reversals = [];
        while ($row = $result->fetchAssociative()) {
            $reversals[(string)$row['type']] = ($reversals[(string)$row['type']] ?? 0) + (int)$row['points'];
        }
        return $reversals;
    }
}
