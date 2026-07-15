<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Service\Exception;

use GoldeneZeiten\Products\Core\Service\Order\Exception\OrderPlacementExceptionInterface;

final class InsufficientCreditPointsException extends \RuntimeException implements OrderPlacementExceptionInterface {}
