<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\EventListener;

use GoldeneZeiten\Products\Core\Domain\Enum\OrderStatus;
use GoldeneZeiten\Products\Core\Event\OrderStatusChangedEvent;
use GoldeneZeiten\Products\CreditPoints\Service\CreditPointsReversalService;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Refunds the points spent on an order cancelled before it was shipped, and takes back the points it
 * earned. A shipped order was delivered, so its points stand.
 */
#[AsEventListener]
final class RevertCreditPointsOnOrderCancellationListener
{
    public function __construct(
        private readonly CreditPointsReversalService $creditPointsReversalService
    ) {}

    public function __invoke(OrderStatusChangedEvent $event): void
    {
        if ($event->getNewStatus() !== OrderStatus::CANCELLED || $event->getOrderUid() === 0) {
            return;
        }
        if (in_array($event->getPreviousStatus(), [OrderStatus::SHIPPED, OrderStatus::COMPLETED], true)) {
            return;
        }

        $this->creditPointsReversalService->revertForOrder($event->getOrderUid());
    }
}
