<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Domain\Enum;

enum CreditPointsTransactionType: string
{
    case EARN = 'earn';
    case REDEEM = 'redeem';
    case ADJUSTMENT = 'adjustment';
    /**
     * Points spent on an order that was cancelled, given back.
     */
    case REFUND = 'refund';
    /**
     * Points earned by an order that was cancelled, taken back.
     */
    case REVOKE = 'revoke';
}
