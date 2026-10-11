<?php

declare(strict_types=1);

namespace GoldeneZeiten\Products\CreditPoints\Service;

use GoldeneZeiten\Products\CreditPoints\Domain\Enum\CreditPointsTransactionType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Undoes the points bookings of a cancelled order: the points spent on it are refunded, the points it
 * earned are taken back. Earned points the customer has spent since can only be taken back as far as the
 * balance reaches; the rest is logged. Each reversal is booked once per order, however often it runs.
 */
final class CreditPointsReversalService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly CreditPointsBalanceService $creditPointsBalanceService,
        private readonly LoggerInterface $logger
    ) {}

    public function revertForOrder(int $orderUid): void
    {
        foreach ($this->bookingsByUser($orderUid) as $frontendUser => $bookings) {
            $this->refundSpent($orderUid, $frontendUser, $bookings);
            $this->revokeEarned($orderUid, $frontendUser, $bookings);
        }
    }

    /**
     * @param array<string, int> $bookings points by transaction type
     */
    private function refundSpent(int $orderUid, int $frontendUser, array $bookings): void
    {
        $spent = -($bookings[CreditPointsTransactionType::REDEEM->value] ?? 0);
        if ($spent <= 0 || isset($bookings[CreditPointsTransactionType::REFUND->value])) {
            return;
        }
        $this->creditPointsBalanceService->credit($frontendUser, $spent);
        $this->book($orderUid, $frontendUser, $spent, CreditPointsTransactionType::REFUND);
    }

    /**
     * @param array<string, int> $bookings points by transaction type
     */
    private function revokeEarned(int $orderUid, int $frontendUser, array $bookings): void
    {
        $earned = $bookings[CreditPointsTransactionType::EARN->value] ?? 0;
        if ($earned <= 0 || isset($bookings[CreditPointsTransactionType::REVOKE->value])) {
            return;
        }
        $revocable = min($earned, $this->creditPointsBalanceService->getBalance($frontendUser));
        if ($revocable > 0 && !$this->creditPointsBalanceService->debitIfAffordable($frontendUser, $revocable)) {
            $revocable = 0;
        }
        $this->book($orderUid, $frontendUser, -$revocable, CreditPointsTransactionType::REVOKE);
        if ($revocable < $earned) {
            $this->logger->warning(
                sprintf('Order %d was cancelled, but %d of the %d credit points it earned were spent already and could not be revoked.', $orderUid, $earned - $revocable, $earned),
                ['frontendUser' => $frontendUser]
            );
        }
    }

    /**
     * @return array<int, array<string, int>> points by transaction type, by frontend user
     */
    private function bookingsByUser(int $orderUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_products_domain_model_creditpointstransaction');
        $result = $queryBuilder
            ->select('frontend_user', 'type', 'points')
            ->from('tx_products_domain_model_creditpointstransaction')
            ->where($queryBuilder->expr()->eq('order_uid', $queryBuilder->createNamedParameter($orderUid, Connection::PARAM_INT)))
            ->executeQuery();
        $bookings = [];
        while ($row = $result->fetchAssociative()) {
            $frontendUser = (int)$row['frontend_user'];
            $type = (string)$row['type'];
            $bookings[$frontendUser][$type] = ($bookings[$frontendUser][$type] ?? 0) + (int)$row['points'];
        }
        return $bookings;
    }

    private function book(int $orderUid, int $frontendUser, int $points, CreditPointsTransactionType $type): void
    {
        $this->connectionPool->getConnectionForTable('tx_products_domain_model_creditpointstransaction')->insert(
            'tx_products_domain_model_creditpointstransaction',
            [
                'pid' => 0,
                'frontend_user' => $frontendUser,
                'order_uid' => $orderUid,
                'points' => $points,
                'type' => $type->value,
                'created' => time(),
                'crdate' => time(),
                'tstamp' => time(),
            ]
        );
    }
}
